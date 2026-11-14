<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Emails the office about every service request a customer creates.
 *
 * Todd, 2026-11-14: "I need the system to email office@brookstoneoutdoors.com
 * when a service request is created from a customer. The email needs to
 * include as much info as possible and a link to the service request and/or
 * at least to the report." One path for every public form (winterize, fall
 * cleanup, holiday lights, contact): fired from hook_ENTITY_TYPE_insert when
 * the record's owner is uid 0, which is how every public form saves and no
 * office tool does. The record is the system of record; the email is the
 * nudge — a failed send is logged and never breaks the submission.
 *
 * Body = every populated field on the record, grouped (customer as submitted ·
 * match · details), values resolved to labels, plus the three office links.
 * Plain text: it must read on a phone and survive any mail client.
 */
final class OfficeNotifier {

  /**
   * Fixed canonical host: the request host is unreliable outside a browser
   * request (CLI/cron yield "http://default") and the office is always on the
   * live domain. Same decision as the former contact-form backstop.
   */
  private const BASE = 'https://brookstoneoutdoors.com';

  /** Internal bookkeeping fields that would only add noise. */
  private const SKIP = ['field_notice_version', 'field_match_candidates', 'field_office_notes', 'field_converted_by', 'field_converted_on'];

  /** Shown in the header block, so not repeated in the groups. */
  private const HEADER = ['field_public_ref', 'field_request_status', 'field_source', 'field_campaign', 'field_service', 'field_service_year'];

  private const CUSTOMER = ['field_submitted_name', 'field_submitted_phone', 'field_submitted_email', 'field_submitted_address', 'field_submitted_zip'];

  private const MATCH = ['field_property', 'field_existing_contract', 'field_existing_work_order', 'field_review_flags'];

  public function __construct(
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Where the email goes: bos_service_request.settings office_notify_email,
   * else the site mail (office@brookstoneoutdoors.com on live).
   */
  public function recipient(): string {
    $to = trim((string) $this->configFactory->get('bos_service_request.settings')->get('office_notify_email'));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $to = (string) $this->configFactory->get('system.site')->get('mail');
    }
    return $to;
  }

  /**
   * Customer-created = owner uid 0. Every public form saves that way; office
   * tools (convert, create customer, admin add form) save as the staff user.
   */
  public function isCustomerCreated(EntityInterface $entity): bool {
    return $entity->getEntityTypeId() === 'service_request'
      && method_exists($entity, 'getOwnerId')
      && (int) $entity->getOwnerId() === 0;
  }

  /**
   * Send the notification. Returns TRUE when the mail system accepted it.
   */
  public function notifyNew(ContentEntityInterface $sr, ?string $to = NULL, string $subject_prefix = ''): bool {
    $to = $to ?: $this->recipient();
    $built = $this->build($sr);
    $params = [
      'subject' => $subject_prefix . $built['subject'],
      'body' => $built['body'],
      'reply_to' => $built['reply_to'],
    ];
    $result = $this->mailManager->mail('bos_service_request', 'office_notification', $to, 'en', $params);
    $ok = !empty($result['result']);
    $this->logger->log($ok ? 'info' : 'warning', 'Office notification for service request @id (@bundle) → @to: @r', [
      '@id' => $sr->id(),
      '@bundle' => $sr->bundle(),
      '@to' => $to,
      '@r' => $ok ? 'accepted by the mail system' : 'NOT SENT (mail system refused)',
    ]);
    return $ok;
  }

  /**
   * Subject, plain-text body and reply-to for one record (also used by the
   * verify script to preview without sending).
   *
   * @return array{subject: string, body: string, reply_to: string}
   */
  public function build(ContentEntityInterface $sr): array {
    $bundle = $sr->bundle();
    $bundle_label = (string) ($this->bundleInfo->getBundleInfo('service_request')[$bundle]['label'] ?? $bundle);
    $defs = array_filter(
      $this->fieldManager->getFieldDefinitions('service_request', $bundle),
      static fn($d, $n) => str_starts_with($n, 'field_'),
      ARRAY_FILTER_USE_BOTH
    );
    $val = fn(string $f) => isset($defs[$f]) ? $this->formatField($sr, $f, $defs[$f]) : '';

    $name = trim((string) $this->raw($sr, 'field_submitted_name'));
    $address = trim((string) $this->raw($sr, 'field_submitted_address'));
    $zip = trim((string) $this->raw($sr, 'field_submitted_zip'));
    $ref = trim((string) $this->raw($sr, 'field_public_ref')) ?: ('#' . $sr->id());
    $email = trim((string) $this->raw($sr, 'field_submitted_email'));

    // Subject: the kind of request, then who and where.
    $kind = $bundle_label . ' request';
    if ($bundle === 'general_inquiry') {
      $topic = (string) $this->raw($sr, 'field_topic');
      $kind = match ($topic) {
        'problem' => '⚠ PROBLEM report',
        'commercial' => 'COMMERCIAL/HOA inquiry',
        'quote' => 'QUOTE request',
        default => 'Contact: ' . ($val('field_topic') ?: 'inquiry'),
      };
    }
    $where = $address !== '' ? $address : ($zip !== '' ? 'ZIP ' . $zip : $ref);
    $subject = '[BOS] New ' . $kind . ' — ' . ($name !== '' ? $name . ', ' : '') . $where;

    $tz = new \DateTimeZone('America/Denver');
    $created = $sr->hasField('created') && $sr->get('created')->value
      ? (new \DateTime('@' . (int) $sr->get('created')->value))->setTimezone($tz)->format('D m/d/Y g:i A') . ' MT'
      : '';

    $lines = [];
    $lines[] = 'NEW SERVICE REQUEST — ' . strtoupper($bundle_label);
    $lines[] = 'Reference: ' . $ref . ($created !== '' ? '   Submitted: ' . $created : '');
    $status = $val('field_request_status');
    $channel = trim(($val('field_source') ?: '') . ($this->raw($sr, 'field_campaign') ? ' · campaign ' . $this->raw($sr, 'field_campaign') : ''));
    $lines[] = 'Status: ' . ($status ?: 'New') . ($channel !== '' ? '   Channel: ' . $channel : '');
    $service = $val('field_service');
    if ($service !== '') {
      $lines[] = 'Service: ' . $service . ($this->raw($sr, 'field_service_year') ? ' (' . $this->raw($sr, 'field_service_year') . ')' : '');
    }
    $lines[] = '';
    // Bare URLs, one per line: the legacy mail path html-to-texts the body
    // (runs of spaces collapse) and wraps lines, and a wrapped URL is a broken
    // link on a phone. A line that is only a URL is never wrapped.
    $lines[] = 'Links — this request · office queue · campaign report:';
    $lines[] = self::BASE . '/service_request/' . $sr->id();
    $lines[] = self::BASE . '/admin/office/service-requests';
    $lines[] = self::BASE . '/admin/office/service-requests/report';

    $group = function (string $title, array $fields) use (&$lines, $defs, $val): void {
      $rows = [];
      foreach ($fields as $f) {
        if (!isset($defs[$f])) {
          continue;
        }
        $v = $val($f);
        if ($v === '') {
          continue;
        }
        $rows[] = [(string) $defs[$f]->getLabel(), $v];
      }
      if (!$rows) {
        return;
      }
      $lines[] = '';
      $lines[] = $title;
      $w = min(28, max(array_map(static fn($r) => mb_strlen($r[0]), $rows)) + 1);
      foreach ($rows as [$label, $v]) {
        if (str_contains($v, "\n")) {
          $lines[] = $label . ':';
          foreach (explode("\n", $v) as $l) {
            $lines[] = '    ' . $l;
          }
        }
        else {
          // Always at least one space after the colon, even past the pad width.
          $lines[] = str_pad($label . ':', $w) . ' ' . $v;
        }
      }
    };

    $group('CUSTOMER (as submitted)', self::CUSTOMER);
    $match_fields = self::MATCH;
    $group('MATCH', $match_fields);
    // Match candidates: a count, not the JSON.
    $cand = (string) $this->raw($sr, 'field_match_candidates');
    if ($cand !== '') {
      $decoded = json_decode($cand, TRUE);
      $lines[] = 'Match candidates: ' . (is_array($decoded) ? count($decoded) . ' considered' : 'recorded');
    }
    $shown = array_merge(self::SKIP, self::HEADER, self::CUSTOMER, self::MATCH);
    $group('REQUEST DETAILS', array_values(array_diff(array_keys($defs), $shown)));

    $lines[] = '';
    $lines[] = 'Sent by BOS when the request was created. Reply goes to the customer when they left an email.';

    return [
      'subject' => $subject,
      'body' => implode("\n", $lines),
      'reply_to' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
    ];
  }

  private function raw(ContentEntityInterface $sr, string $field): mixed {
    if (!$sr->hasField($field) || $sr->get($field)->isEmpty()) {
      return NULL;
    }
    $item = $sr->get($field)->first();
    return $item->target_id ?? $item->value ?? NULL;
  }

  /**
   * One field → a human string ('' when empty). Labels for lists and
   * references, Yes/No for booleans, MT for timestamps, links for the entities
   * the office would open.
   */
  private function formatField(ContentEntityInterface $sr, string $field, $def): string {
    if (!$sr->hasField($field) || $sr->get($field)->isEmpty()) {
      return '';
    }
    $items = $sr->get($field);
    $type = $def->getType();
    $tz = new \DateTimeZone('America/Denver');
    $out = [];
    foreach ($items as $item) {
      switch ($type) {
        case 'entity_reference':
          $e = $item->entity;
          if (!$e) {
            $out[] = '#' . $item->target_id . ' (missing)';
            break;
          }
          $label = (string) $e->label();
          if ($e->getEntityTypeId() === 'properties') {
            $nick = $e->hasField('field_nickname') ? trim((string) $e->get('field_nickname')->value) : '';
            $addr = $e->hasField('field_full_address') ? trim((string) $e->get('field_full_address')->value) : '';
            $label = trim(($nick !== '' ? $nick : $label) . ($addr !== '' ? ' — ' . $addr : ''));
          }
          if ($e->getEntityTypeId() === 'user') {
            $label = $e->getDisplayName();
          }
          if (in_array($e->getEntityTypeId(), ['properties', 'work_order', 'contracts', 'estimate_request', 'service_request'], TRUE)) {
            try {
              $label .= '  ' . self::BASE . $e->toUrl()->toString();
            }
            catch (\Throwable $ex) {
              // No canonical route — label alone.
            }
          }
          $out[] = $label;
          break;

        case 'list_string':
        case 'list_integer':
          $allowed = $def->getSetting('allowed_values') ?? [];
          $out[] = (string) ($allowed[$item->value] ?? $item->value);
          break;

        case 'boolean':
          $out[] = ((int) $item->value) ? 'Yes' : 'No';
          break;

        case 'timestamp':
        case 'created':
        case 'changed':
          $out[] = (new \DateTime('@' . (int) $item->value))->setTimezone($tz)->format('m/d/Y g:i A') . ' MT';
          break;

        case 'datetime':
          try {
            $out[] = (new \DateTime((string) $item->value, new \DateTimeZone('UTC')))->setTimezone($tz)->format('m/d/Y g:i A') . ' MT';
          }
          catch (\Throwable $ex) {
            $out[] = (string) $item->value;
          }
          break;

        default:
          $out[] = trim((string) ($item->value ?? ''));
      }
    }
    $out = array_filter($out, static fn($v) => $v !== '');
    return implode($type === 'string_long' || $type === 'text_long' ? "\n" : ', ', $out);
  }

}
