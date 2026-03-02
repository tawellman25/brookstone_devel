<?php

declare(strict_types=1);

namespace Drupal\estimate_notifications\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Sends an email notification when an estimate request is assigned to an estimator.
 *
 * Rules:
 * - On insert: fire if field_assigned_to is set.
 * - On update: fire only if field_assigned_to changed from empty to a value.
 *   Re-assignment (value → different value) is intentionally ignored here.
 */
final class EstimateRequestNotifier {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly MailManagerInterface $mailManager,
  ) {}

  /**
   * Apply insert rule: send if field_assigned_to is populated.
   */
  public function applyInsert(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'estimate_request') {
      return;
    }
    if (!$entity->hasField('field_assigned_to') || $entity->get('field_assigned_to')->isEmpty()) {
      return;
    }
    $this->sendNotification($entity);
  }

  /**
   * Apply update rule: send only if field_assigned_to changed from empty to a value.
   */
  public function applyUpdate(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'estimate_request') {
      return;
    }

    $original = $entity->original ?? NULL;
    if ($original === NULL) {
      return;
    }

    // Was already assigned — skip (re-assignment handled separately later).
    if ($original->hasField('field_assigned_to') && !$original->get('field_assigned_to')->isEmpty()) {
      return;
    }

    // Still unassigned — nothing to notify.
    if (!$entity->hasField('field_assigned_to') || $entity->get('field_assigned_to')->isEmpty()) {
      return;
    }

    $this->sendNotification($entity);
  }

  /**
   * Build and send the assignment notification email.
   */
  private function sendNotification(EntityInterface $entity): void {
    $eid = (int) $entity->id();

    // Load assigned user — bail if no email address.
    $assigned_uid = (int) ($entity->get('field_assigned_to')->target_id ?? 0);
    if ($assigned_uid <= 0) {
      return;
    }
    $assigned_user = $this->entityTypeManager->getStorage('user')->load($assigned_uid);
    if ($assigned_user === NULL) {
      $this->loggerFactory->get('estimate_notifications')
        ->warning('Assigned user @uid not found for estimate_request @eid.', [
          '@uid' => $assigned_uid,
          '@eid' => $eid,
        ]);
      return;
    }
    $to = $assigned_user->getEmail();
    if (empty($to)) {
      $this->loggerFactory->get('estimate_notifications')
        ->warning('Assigned user @uid has no email address; skipping notification for estimate_request @eid.', [
          '@uid' => $assigned_uid,
          '@eid' => $eid,
        ]);
      return;
    }
    $assigned_name = $assigned_user->getDisplayName();

    // Service term name.
    $service_name = '(unknown)';
    if ($entity->hasField('field_service') && !$entity->get('field_service')->isEmpty()) {
      $tid = (int) ($entity->get('field_service')->target_id ?? 0);
      if ($tid > 0) {
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
        if ($term !== NULL) {
          $service_name = $term->getName();
        }
      }
    }

    // Property label.
    $property_label = '(unknown)';
    if ($entity->hasField('field_property') && !$entity->get('field_property')->isEmpty()) {
      $pid = (int) ($entity->get('field_property')->target_id ?? 0);
      if ($pid > 0) {
        $property = $this->entityTypeManager->getStorage('properties')->load($pid);
        if ($property !== NULL) {
          $property_label = $property->label() ?? '(unknown)';
        }
      }
    }

    // Owner (client) display name.
    $owner_name = '(unknown)';
    if ($entity->hasField('field_owner') && !$entity->get('field_owner')->isEmpty()) {
      $uid = (int) ($entity->get('field_owner')->target_id ?? 0);
      if ($uid > 0) {
        $owner = $this->entityTypeManager->getStorage('user')->load($uid);
        if ($owner !== NULL) {
          $owner_name = $owner->getDisplayName();
        }
      }
    }

    // Priority.
    $priority = '(unknown)';
    if ($entity->hasField('field_priority') && !$entity->get('field_priority')->isEmpty()) {
      $raw = (string) ($entity->get('field_priority')->value ?? '');
      if ($raw !== '') {
        $priority = ucfirst($raw);
      }
    }

    // Assigned by (current logged-in user).
    $assigned_by = $this->currentUser->id()
      ? ($this->currentUser->getDisplayName() ?: $this->currentUser->getAccountName())
      : '(system)';

    // Canonical URL.
    $url = $entity->toUrl('canonical')->setAbsolute(TRUE)->toString();

    // Build subject.
    $subject = sprintf('New Estimate Request Assigned — #%d', $eid);

    // Build HTML body.
    $html_body = [
      '<p style="font-family: Arial, sans-serif; font-size: 14px; margin: 0 0 12px 0;">A new estimate request has been assigned to you.</p>',
      '<table style="font-family: Arial, sans-serif; font-size: 14px; border-collapse: collapse;">',
      sprintf('<tr><td style="padding: 4px 16px 4px 0; font-weight: bold;">Request #:</td><td style="padding: 4px 0;">%d</td></tr>', $eid),
      sprintf('<tr><td style="padding: 4px 16px 4px 0; font-weight: bold;">Service:</td><td style="padding: 4px 0;">%s</td></tr>', htmlspecialchars($service_name)),
      sprintf('<tr><td style="padding: 4px 16px 4px 0; font-weight: bold;">Property:</td><td style="padding: 4px 0;">%s</td></tr>', htmlspecialchars($property_label)),
      sprintf('<tr><td style="padding: 4px 16px 4px 0; font-weight: bold;">Client:</td><td style="padding: 4px 0;">%s</td></tr>', htmlspecialchars($owner_name)),
      sprintf('<tr><td style="padding: 4px 16px 4px 0; font-weight: bold;">Priority:</td><td style="padding: 4px 0;">%s</td></tr>', htmlspecialchars($priority)),
      sprintf('<tr><td style="padding: 4px 16px 4px 0; font-weight: bold;">Assigned by:</td><td style="padding: 4px 0;">%s</td></tr>', htmlspecialchars($assigned_by)),
      '</table>',
      sprintf('<p style="font-family: Arial, sans-serif; font-size: 14px; margin: 14px 0 0 0;"><a href="%s">View Estimate Request #%d</a></p>', $url, $eid),
    ];

    // Build plain-text body.
    $plain_body = [
      'A new estimate request has been assigned to you.',
      '',
      sprintf('Request #:    %d', $eid),
      sprintf('Service:      %s', $service_name),
      sprintf('Property:     %s', $property_label),
      sprintf('Client:       %s', $owner_name),
      sprintf('Priority:     %s', $priority),
      sprintf('Assigned by:  %s', $assigned_by),
      '',
      sprintf('View: %s', $url),
    ];

    $params = [
      'subject' => $subject,
      'body'    => $html_body,
      'plain'   => $plain_body,
    ];

    $result = $this->mailManager->mail(
      'estimate_notifications',
      'assignment_notification',
      $to,
      'en',
      $params,
      NULL,
      TRUE
    );

    if (!empty($result['result'])) {
      $this->loggerFactory->get('estimate_notifications')
        ->info('Assignment notification sent for estimate_request @eid to @to (@name).', [
          '@eid'  => $eid,
          '@to'   => $to,
          '@name' => $assigned_name,
        ]);
    }
    else {
      $this->loggerFactory->get('estimate_notifications')
        ->error('Failed to send assignment notification for estimate_request @eid to @to.', [
          '@eid' => $eid,
          '@to'  => $to,
        ]);
    }
  }

}
