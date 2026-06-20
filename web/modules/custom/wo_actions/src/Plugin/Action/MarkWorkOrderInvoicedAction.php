<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Marks or re-marks a Work Order as Invoiced.
 *
 * Eligibility: only Complete (1097) WOs may be invoiced; an already-Invoiced
 * (1281) WO is accepted as a no-op re-mark. Any other status is skipped with a
 * per-row warning and a logged record, so a single ineligible row can never
 * abort the VBO batch (the failure mode that caused the 2026-06-19 mow-crew
 * billing crash, where select-all swept Scheduled (1091) WOs into the batch).
 *
 * Write order is deliberate: the status-update audit record is created FIRST
 * (its presave propagates field_status -> Invoiced and is what the wo_shared
 * transition guard validates). Only after that succeeds is field_invoiced set.
 * This is the inverse of the original ordering, which set field_invoiced=1
 * before the status step and so left an orphaned flag when the guard threw.
 *
 * @Action(
 *   id = "mark_work_order_invoiced_action",
 *   label = @Translation("Mark WO Invoiced"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class MarkWorkOrderInvoicedAction extends ViewsBulkOperationsActionBase {

  /**
   * BOS wo_status taxonomy term IDs.
   */
  protected const STATUS_COMPLETE = 1097;
  protected const STATUS_INVOICED = 1281;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    $messenger = \Drupal::messenger();
    $logger = \Drupal::logger('wo_actions');

    if (!$entity || $entity->getEntityTypeId() !== 'work_order') {
      return;
    }

    $current_user = \Drupal::currentUser();
    $current_user_name = $current_user->getDisplayName();
    $current_user_id = $current_user->id();

    $wo_id = $entity->id();
    $wo_label = ($entity->hasField('field_work_order_id') && !$entity->get('field_work_order_id')->isEmpty())
      ? '#' . $entity->get('field_work_order_id')->value
      : '#' . $wo_id;

    $current_status = (int) ($entity->get('field_status')->target_id ?? 0);

    // Eligibility gate. Invoicing is valid only from Complete; an already
    // Invoiced WO is allowed as a no-op re-mark. Everything else is skipped.
    // This is what prevents the batch-killing throw: the wo_shared guard is
    // never reached on an ineligible WO because we never attempt the
    // transition.
    if ($current_status !== self::STATUS_COMPLETE && $current_status !== self::STATUS_INVOICED) {
      $messenger->addWarning(
        "Skipped WO $wo_label — not eligible for invoicing. It must be Complete first; "
        . "current status term ID is $current_status (the crew has not signed off)."
      );
      // Logged so a repeat of the select-all-sweeps-ineligible-WOs pattern is
      // visible in dblog without forensics: who, which WO, what status, why.
      $logger->warning(
        'Invoice action SKIPPED ineligible WO @label (id @id): status @status, not Complete. Attempted by @user (uid @uid).',
        [
          '@label' => $wo_label,
          '@id' => $wo_id,
          '@status' => $current_status,
          '@user' => $current_user_name,
          '@uid' => $current_user_id,
        ]
      );
      return;
    }

    $was_invoiced = (int) ($entity->get('field_invoiced')->value ?? 0) === 1;

    try {
      // 1) Create the audit record FIRST. Its presave (wo_status_updates)
      // propagates field_status -> Invoiced onto the WO and is validated by
      // the wo_shared transition guard. If anything throws, it throws here,
      // BEFORE field_invoiced is touched — so no orphaned flag is possible.
      $status_note = !$was_invoiced
        ? "$current_user_name marked this Work Order as Invoiced."
        : "$current_user_name re-confirmed this Work Order as Invoiced.";

      $status_update = \Drupal::entityTypeManager()
        ->getStorage('wo_status_updates')
        ->create([
          'type' => 'update',
          'field_status_of_wo' => $wo_id,
          'field_status' => self::STATUS_INVOICED,
          'field_status_change_note' => $status_note,
          'uid' => $current_user_id,
        ]);
      $status_update->save();

      // 2) Status reached Invoiced — now set the boolean flag. Reload to read
      // post-presave state rather than trusting the stale VBO-supplied
      // object, and only write if the flag isn't already set.
      if (!$was_invoiced) {
        $wo = \Drupal::entityTypeManager()->getStorage('work_order')->load($wo_id);
        if ($wo && (int) ($wo->get('field_invoiced')->value ?? 0) !== 1) {
          $wo->set('field_invoiced', 1);
          $wo->save();
        }
      }

      $messenger->addMessage("WO $wo_label updated to Invoiced.");
      $logger->info(
        'Invoice action marked WO @label (id @id) Invoiced. By @user (uid @uid).',
        ['@label' => $wo_label, '@id' => $wo_id, '@user' => $current_user_name, '@uid' => $current_user_id]
      );
    }
    catch (\Exception $e) {
      // Backstop. The eligibility gate should make this unreachable, but if an
      // upstream change ever lets an ineligible WO through, we catch the
      // guard's throw per-row so the batch continues instead of aborting.
      $messenger->addError("Could not invoice WO $wo_label: " . $e->getMessage());
      $logger->error(
        'Invoice action FAILED for WO @label (id @id): @msg. By @user (uid @uid).',
        [
          '@label' => $wo_label,
          '@id' => $wo_id,
          '@msg' => $e->getMessage(),
          '@user' => $current_user_name,
          '@uid' => $current_user_id,
        ]
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
