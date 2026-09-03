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
   * Why a customer declined (machine key => label). Recorded on "Not interested".
   */
  public const DECLINE_REASONS = [
    'moved' => 'Moved / no longer owns',
    'sold' => 'Sold the property',
    'competitor' => 'Using another company',
    'diy' => 'Doing it themselves',
    'price' => 'Price / too expensive',
    'no_need' => 'No longer needs it',
    'deceased' => 'Deceased / estate',
    'other' => 'Other',
  ];

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
   * Clamp a requested look-back (in seasons) to a sane 1..10.
   */
  public function clampLookback(int $years): int {
    return max(1, min(10, $years));
  }

  /**
   * Compute the win-back rows, newest-relevant first, in route order.
   *
   * @param int $lookback_years
   *   How many prior seasons to include (1 = just last year). Clamped 1..10.
   *
   * @return array[]
   *   Each row: property_id, nickname, street, city, zip, zones, contact_name,
   *   owner_name, phone, email, last_wo_id, last_date, last_total, last_status,
   *   was_canceled, state (['outcome','by','time_ts'] or NULL).
   */
  public function getRows(int $lookback_years = 1): array {
    $target_year = $this->targetYear();
    $lookback = $this->clampLookback($lookback_years);
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

    // Prior-season winterizing WOs across the look-back window: from N seasons
    // ago (Aug 15) through the end of last year. Winterizing WOs only exist in
    // fall, so a continuous range cleanly spans each prior season.
    $source_ids = $wo_s->getQuery()->accessCheck(FALSE)
      ->condition('type', 'sprinkler_winterizing')
      ->condition('created', [$ts(($target_year - $lookback) . '-08-15'), $ts(($target_year - 1) . '-12-31')], 'BETWEEN')
      // Newest first so the first WO seen per property is its latest season.
      ->sort('id', 'DESC')
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
      // One row per property — WOs are newest-first, so the first is the latest
      // prior winterization; skip any older season for the same property.
      if (isset($seen[$pid])) {
        continue;
      }
      $seen[$pid] = TRUE;

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
        'last_date'    => $this->completionDate((int) $wo->id(), $tz) ?: ($wo->get('created')->isEmpty() ? '' :
          (new \DateTime('@' . $wo->get('created')->value))->setTimezone($tz)->format('m/d/Y')),
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
   * Win-back summary counts for the header.
   *
   * The season's winterize WOs are created in one big carry-forward batch (one
   * day), then win-back WOs are created ad-hoc afterward. So we split
   * came-back customers by whether their current-year winterize WO was created
   * in the batch (carried forward) or AFTER it (won back this season).
   *
   *   source_total — distinct properties winterized in the look-back window
   *   came_back    — of those, how many now have a current-year winterize
   *   carried      — of came_back, created in the carry-forward batch
   *   won_back     — of came_back, created AFTER the batch (this season's wins)
   *   batch_date   — the carry-forward batch day (m/d/Y), for the label
   *   pct          — came_back / source_total, rounded
   */
  public function getSummary(int $lookback_years = 1): array {
    $target_year = $this->targetYear();
    $lookback = $this->clampLookback($lookback_years);
    $tz = new \DateTimeZone(date_default_timezone_get());
    $ts = fn(string $ymd) => (new DrupalDateTime($ymd . ' 00:00:00', $tz))->getTimestamp();
    $db = \Drupal::database();

    // Source: distinct properties winterized in the look-back window.
    $sq = $db->select('work_order__field_property', 'wop');
    $sq->join('work_order_field_data', 'wo', 'wo.id = wop.entity_id');
    $sq->condition('wo.type', 'sprinkler_winterizing');
    $sq->condition('wop.deleted', 0);
    $sq->condition('wo.created', [$ts(($target_year - $lookback) . '-08-15'), $ts(($target_year - 1) . '-12-31')], 'BETWEEN');
    $sq->fields('wop', ['field_property_target_id']);
    $sq->distinct();
    $source = array_flip(array_map('intval', $sq->execute()->fetchCol()));

    // Current-year winterize WOs: earliest created per property + day tallies.
    $cq = $db->select('work_order__field_property', 'wop');
    $cq->join('work_order_field_data', 'wo', 'wo.id = wop.entity_id');
    $cq->condition('wo.type', 'sprinkler_winterizing');
    $cq->condition('wop.deleted', 0);
    $cq->condition('wo.created', $ts($target_year . '-01-01'), '>=');
    $cq->addField('wop', 'field_property_target_id', 'pid');
    $cq->addField('wo', 'created', 'created');
    $prop_first = [];
    $by_day = [];
    foreach ($cq->execute() as $r) {
      $pid = (int) $r->pid;
      $c = (int) $r->created;
      if (!isset($prop_first[$pid]) || $c < $prop_first[$pid]) {
        $prop_first[$pid] = $c;
      }
      $day = (new \DateTime('@' . $c))->setTimezone($tz)->format('Y-m-d');
      $by_day[$day] = ($by_day[$day] ?? 0) + 1;
    }
    // The carry-forward batch = the day the most winterize WOs were created.
    arsort($by_day);
    $batch_day = key($by_day);
    $batch_cutoff = $batch_day ? $ts($batch_day) + 86399 : 0;

    $came_back = $carried = $won_back = 0;
    foreach ($prop_first as $pid => $first_created) {
      if (!isset($source[$pid])) {
        continue;
      }
      $came_back++;
      if ($first_created > $batch_cutoff) {
        $won_back++;
      }
      else {
        $carried++;
      }
    }
    $source_total = count($source);
    return [
      'source_total' => $source_total,
      'came_back' => $came_back,
      'carried' => $carried,
      'won_back' => $won_back,
      'batch_date' => $batch_day ? (new \DateTime($batch_day))->format('m/d/Y') : '',
      'pct' => $source_total ? (int) round($came_back / $source_total * 100) : 0,
    ];
  }

  /**
   * The date the WO was signed off / completed (from its wo_complete_info
   * record's field_date_completed), formatted m/d/Y, or '' if not completed.
   * Bundle-agnostic — matches whichever crew signed it off.
   */
  protected function completionDate(int $wo_id, \DateTimeZone $tz): string {
    $ids = \Drupal::entityQuery('wo_complete_info')
      ->condition('field_work_order', $wo_id)
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return '';
    }
    $ci = \Drupal::entityTypeManager()->getStorage('wo_complete_info')->load(reset($ids));
    if (!$ci || !$ci->hasField('field_date_completed') || $ci->get('field_date_completed')->isEmpty()) {
      return '';
    }
    return (new \DateTime('@' . $ci->get('field_date_completed')->value))->setTimezone($tz)->format('m/d/Y');
  }

  /**
   * Record a call outcome for a property. Returns the stored state.
   *
   * For "declined", $reason (a DECLINE_REASONS key) and an optional free-text
   * $note are captured so the office can see why customers are dropping.
   */
  public function mark(int $pid, string $outcome, string $by, string $reason = '', string $note = ''): array {
    $valid = ['left_message', 'no_answer', 'reached', 'declined'];
    if (!in_array($outcome, $valid, TRUE)) {
      throw new \InvalidArgumentException('Unknown outcome: ' . $outcome);
    }
    $rec = [
      'outcome' => $outcome,
      'by' => $by,
      'time_ts' => $this->time->getRequestTime(),
    ];
    if ($outcome === 'declined') {
      $rec['reason'] = array_key_exists($reason, self::DECLINE_REASONS) ? $reason : 'other';
      $rec['note'] = mb_substr(trim($note), 0, 500);
    }
    \Drupal::keyValue(self::STATE_COLLECTION)->set((string) $this->stateKey($pid), $rec);
    return $rec;
  }

  /**
   * Declined customers this season, with reason — newest first.
   *
   * @return array[]
   *   Each: property_id, name, street, city, reason, reason_label, note, by,
   *   time (m/d/Y).
   */
  public function getDeclined(): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $out = [];
    foreach ($this->allState() as $pid => $st) {
      if (($st['outcome'] ?? '') !== 'declined') {
        continue;
      }
      $prop = $this->etm->getStorage('properties')->load($pid);
      $owner_uid = $this->findLatestOwner((int) $pid);
      $contact = NULL;
      if ($prop) {
        foreach (['field_primary_contact_ref', 'field_contacts'] as $f) {
          if ($contact = $this->refEntity($prop, $f)) {
            break;
          }
        }
      }
      if (!$contact && $owner_uid) {
        $contact = $this->profileContact($owner_uid);
      }
      $owner_user = $owner_uid > 1 ? $this->etm->getStorage('user')->load($owner_uid) : NULL;
      $name = $contact ? $contact->label() : ($owner_user ? $owner_user->getDisplayName() : ($prop ? $prop->label() : ('Property ' . $pid)));

      $city = '';
      if ($prop) {
        $zipref = $this->refEntity($prop, 'field_zipcode_reference');
        if ($zipref) {
          $ce = $this->refEntity($zipref, 'field_city');
          $city = $ce ? $ce->label() : '';
        }
      }
      $reason = $st['reason'] ?? 'other';
      $out[] = [
        'property_id' => (int) $pid,
        'name' => $name,
        'street' => $prop ? $this->scalar($prop, 'field_street_address') : '',
        'city' => $city,
        'reason' => $reason,
        'reason_label' => self::DECLINE_REASONS[$reason] ?? 'Other',
        'note' => $st['note'] ?? '',
        'by' => $st['by'] ?? '',
        'time' => !empty($st['time_ts']) ? (new \DateTime('@' . $st['time_ts']))->setTimezone($tz)->format('m/d/Y') : '',
        'time_ts' => (int) ($st['time_ts'] ?? 0),
      ];
    }
    usort($out, fn($a, $b) => $b['time_ts'] <=> $a['time_ts']);
    return $out;
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
