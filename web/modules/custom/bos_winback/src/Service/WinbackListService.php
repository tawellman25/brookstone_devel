<?php

declare(strict_types=1);

namespace Drupal\bos_winback\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Computes the winterizing win-back list and holds per-property call state.
 *
 * Win-back = properties winterized last season (prior year, Aug 15 → Dec 31)
 * that have NO sprinkler_winterizing work order this year. Read-only over WO /
 * property / ownership_record / contact data — this service never creates a WO
 * or edits a property. A property drops off the list when it gets a current-year
 * winterizing WO (recomputed each load) or when a caller marks it "declined".
 *
 * Contact resolution mirrors the BOS model (and web/scripts/winterize_winback_list.php):
 * customer = latest ownership_record → client user → customer_profile primary
 * contact; phone = contact.field_phone_number → phone_number → value.
 */
final class WinbackListService {

  /**
   * KeyValue collection for call state, keyed by "<year>:<pid>".
   */
  private const STATE_COLLECTION = 'bos_winback.call_state';

  /**
   * Outcomes that REMOVE a property from the list.
   */
  private const SUPPRESS_OUTCOMES = ['declined'];

  /**
   * WO status: Canceled.
   */
  private const STATUS_CANCELED = 1098;

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The target (current) campaign year.
   */
  public function targetYear(): int {
    return (int) date('Y', $this->time->getRequestTime());
  }

  /**
   * Compute the win-back rows, newest-relevant first, in route order.
   *
   * @return array[]
   *   Each row: property_id, nickname, street, city, zip, zones, contact_name,
   *   owner_name, phone, email, last_wo_id, last_date, last_total, last_status,
   *   was_canceled, state (['outcome','by','time_ts'] or NULL).
   */
  public function getRows(): array {
    $target_year = $this->targetYear();
    $source_year = $target_year - 1;
    $tz = new \DateTimeZone(date_default_timezone_get());
    $ts = fn(string $ymd) => (new DrupalDateTime($ymd . ' 00:00:00', $tz))->getTimestamp();

    $wo_s = $this->etm->getStorage('work_order');

    // Covered this year → properties to exclude.
    $covered = [];
    $target_ids = $wo_s->getQuery()->accessCheck(FALSE)
      ->condition('type', 'sprinkler_winterizing')
      ->condition('created', $ts($target_year . '-01-01'), '>=')
      ->execute();
    foreach ($wo_s->loadMultiple($target_ids) as $wo) {
      if (!$wo->get('field_property')->isEmpty()) {
        $covered[(int) $wo->get('field_property')->first()->getValue()['target_id']] = TRUE;
      }
    }

    // Source-year winterizing WOs.
    $source_ids = $wo_s->getQuery()->accessCheck(FALSE)
      ->condition('type', 'sprinkler_winterizing')
      ->condition('created', [$ts($source_year . '-08-15'), $ts($source_year . '-12-31')], 'BETWEEN')
      ->sort('id')
      ->execute();

    $state = $this->allState();
    $rows = [];
    $seen = [];

    foreach ($wo_s->loadMultiple($source_ids) as $wo) {
      if ($wo->get('field_property')->isEmpty()) {
        continue;
      }
      $pid = (int) $wo->get('field_property')->first()->getValue()['target_id'];
      if (isset($covered[$pid])) {
        continue;
      }
      // Caller marked them declined → off the list.
      $st = $state[$pid] ?? NULL;
      if ($st && in_array($st['outcome'] ?? '', self::SUPPRESS_OUTCOMES, TRUE)) {
        continue;
      }
      // Most recent source WO per property.
      if (isset($seen[$pid]) && $seen[$pid] >= (int) $wo->id()) {
        continue;
      }
      $seen[$pid] = (int) $wo->id();

      $property = $this->refEntity($wo, 'field_property');
      $status = $this->refEntity($wo, 'field_status');
      $status_tid = $status ? (int) $status->id() : 0;

      $owner_uid = $this->findLatestOwner($pid);
      $contact = $this->refEntity($wo, 'field_contact');
      if (!$contact && $property) {
        foreach (['field_primary_contact_ref', 'field_contacts'] as $f) {
          if ($contact = $this->refEntity($property, $f)) {
            break;
          }
        }
      }
      if (!$contact && $owner_uid) {
        $contact = $this->profileContact($owner_uid);
      }

      $owner_user = $owner_uid > 1 ? $this->etm->getStorage('user')->load($owner_uid) : NULL;
      $owner_name = $owner_user ? $owner_user->getDisplayName() : '';

      $phone = $this->contactPhone($contact) ?: $this->ownerPhone($owner_uid);

      $email = ($contact && $contact->hasField('field_email') && !$contact->get('field_email')->isEmpty())
        ? (string) $contact->get('field_email')->value : '';
      if ($email === '' && $owner_user) {
        $email = (string) $owner_user->getEmail();
      }
      // Placeholder migration emails are not real customer addresses.
      if (str_ends_with(strtolower($email), '@sewardslandscape.com')) {
        $email = '';
      }

      $city = $zip = '';
      if ($property) {
        $zipref = $this->refEntity($property, 'field_zipcode_reference');
        if ($zipref) {
          $city_entity = $this->refEntity($zipref, 'field_city');
          $city = $city_entity ? $city_entity->label() : $this->scalar($zipref, 'field_city');
          $zip = $zipref->label();
        }
      }

      $rows[] = [
        'property_id'  => $pid,
        'nickname'     => $property ? $property->label() : '(property missing)',
        'street'       => $property ? $this->scalar($property, 'field_street_address') : '',
        'city'         => $city,
        'zip'          => $zip,
        'zones'        => $this->scalar($wo, 'field_zone_total'),
        'contact_name' => $contact ? $contact->label() : '',
        'owner_name'   => $owner_name,
        'phone'        => $phone,
        'email'        => $email,
        'last_wo_id'   => (int) $wo->id(),
        'last_date'    => $wo->get('created')->isEmpty() ? '' :
          (new \DateTime('@' . $wo->get('created')->value))->setTimezone($tz)->format('m/d/Y'),
        'last_total'   => $this->scalar($wo, 'field_wo_total'),
        'last_status'  => $status ? $status->label() : '',
        'was_canceled' => $status_tid === self::STATUS_CANCELED,
        'state'        => $st,
      ];
    }

    usort($rows, fn($a, $b) => [$a['city'], $a['street']] <=> [$b['city'], $b['street']]);
    return $rows;
  }

  /**
   * Record a call outcome for a property. Returns the stored state.
   */
  public function mark(int $pid, string $outcome, string $by): array {
    $valid = ['left_message', 'no_answer', 'reached', 'declined'];
    if (!in_array($outcome, $valid, TRUE)) {
      throw new \InvalidArgumentException('Unknown outcome: ' . $outcome);
    }
    $rec = [
      'outcome' => $outcome,
      'by' => $by,
      'time_ts' => $this->time->getRequestTime(),
    ];
    \Drupal::keyValue(self::STATE_COLLECTION)->set((string) $this->stateKey($pid), $rec);
    return $rec;
  }

  /**
   * Clear a property's call state (undo).
   */
  public function clearState(int $pid): void {
    \Drupal::keyValue(self::STATE_COLLECTION)->delete((string) $this->stateKey($pid));
  }

  /**
   * All call state for the target year, keyed by pid.
   */
  private function allState(): array {
    $prefix = $this->targetYear() . ':';
    $all = \Drupal::keyValue(self::STATE_COLLECTION)->getAll();
    $out = [];
    foreach ($all as $k => $v) {
      if (str_starts_with($k, $prefix)) {
        $out[(int) substr($k, strlen($prefix))] = $v;
      }
    }
    return $out;
  }

  private function stateKey(int $pid): string {
    return $this->targetYear() . ':' . $pid;
  }

  // --- Resolution helpers (mirror winterize_winback_list.php) --------------

  private function refEntity($entity, string $field) {
    if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    return $entity->get($field)->first()->get('entity')->getTarget()?->getValue();
  }

  private function scalar($entity, string $field): string {
    if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return '';
    }
    $v = $entity->get($field)->first()->getValue();
    return (string) ($v['value'] ?? $v['target_id'] ?? '');
  }

  private function findLatestOwner(int $pid): int {
    $ids = $this->etm->getStorage('ownership_record')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'record')
      ->condition('field_property_reference.target_id', $pid)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return 0;
    }
    $rec = $this->etm->getStorage('ownership_record')->load(reset($ids));
    return ($rec && $rec->hasField('field_property_owner'))
      ? (int) ($rec->get('field_property_owner')->target_id ?? 0) : 0;
  }

  private function profileContact(int $uid) {
    if ($uid <= 1) {
      return NULL;
    }
    $ids = $this->etm->getStorage('profile')->getQuery()->accessCheck(FALSE)
      ->condition('uid', $uid)->condition('type', 'customer_profile')->range(0, 1)->execute();
    if (!$ids) {
      return NULL;
    }
    $p = $this->etm->getStorage('profile')->load(reset($ids));
    return ($p && $p->hasField('field_primary_contact_ref') && !$p->get('field_primary_contact_ref')->isEmpty())
      ? $p->get('field_primary_contact_ref')->entity : NULL;
  }

  private function contactPhone($contact): string {
    if (!$contact || !$contact->hasField('field_phone_number') || $contact->get('field_phone_number')->isEmpty()) {
      return '';
    }
    $pe = $contact->get('field_phone_number')->entity;
    return ($pe && $pe->hasField('field_phone_number') && !$pe->get('field_phone_number')->isEmpty())
      ? (string) $pe->get('field_phone_number')->value : '';
  }

  private function ownerPhone(int $uid): string {
    if ($uid <= 1) {
      return '';
    }
    $ids = $this->etm->getStorage('phone_number')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'profile_phone_numbers')->condition('field_user', $uid)->range(0, 3)->execute();
    foreach ($this->etm->getStorage('phone_number')->loadMultiple($ids) as $pe) {
      if ($pe->hasField('field_phone_number') && !$pe->get('field_phone_number')->isEmpty()) {
        return (string) $pe->get('field_phone_number')->value;
      }
    }
    return '';
  }

}
