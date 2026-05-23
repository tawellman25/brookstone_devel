<?php

namespace Drupal\contract_residential\Plugin\QueueWorker;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates contract-obligated irrigation check-up Work Orders on a rolling horizon.
 *
 * @QueueWorker(
 *   id = "contract_residential_checkup_generator",
 *   title = @Translation("Contract Residential: Irrigation Check-Up WO Generator"),
 *   cron = {"time" = 30}
 * )
 */
final class ContractResidentialCheckupGeneratorQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  // Contract statuses allowed to create/generate Work Orders.
  private const CONTRACT_ALLOWED_STATUS_TIDS = [1123, 1124, 1125];

  // Work Order Status: Open.
  private const WO_STATUS_OPEN_TID = 1089;

  // Check-up frequency tids (irrigation_check_up_frequency).
  private const FREQ_WEEKLY_TID = 1113;
  private const FREQ_BIWEEKLY_TID = 1114;
  private const FREQ_MONTHLY_TID = 1115;
  private const FREQ_MIDSEASON_TID = 1116;

  // Rolling planning horizon.
  private const HORIZON_DAYS = 21;

  // Monthly interval is operational month (4 weeks).
  private const MONTHLY_INTERVAL_DAYS = 28;

  // Zipcode route day: ISO weekday (1=Mon..7=Sun).
  private const ROUTE_DAY_ALLOWED = [1, 2, 3, 4, 5, 6, 7];

  private EntityTypeManagerInterface $etm;
  private EntityTypeBundleInfoInterface $bundleInfo;
  private LoggerChannelFactoryInterface $loggerFactory;
  private QueueFactory $queueFactory;
  private TimeInterface $time;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $etm,
    EntityTypeBundleInfoInterface $bundleInfo,
    LoggerChannelFactoryInterface $loggerFactory,
    QueueFactory $queueFactory,
    TimeInterface $time
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->etm = $etm;
    $this->bundleInfo = $bundleInfo;
    $this->loggerFactory = $loggerFactory;
    $this->queueFactory = $queueFactory;
    $this->time = $time;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) : self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('logger.factory'),
      $container->get('queue'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) : void {
    $op = is_array($data) ? ($data['op'] ?? NULL) : NULL;

    if ($op === 'dispatch') {
      $this->dispatchEligibleSections();
      return;
    }

    if ($op === 'process_section') {
      $section_id = (int) ($data['section_id'] ?? 0);
      if ($section_id > 0) {
        $this->processSection($section_id);
      }
      return;
    }
  }

  /**
   * Enqueue eligible contract_sections that have:
   * - field_contract
   * - field_service
   * - field_check_up_frequency
   */
  private function dispatchEligibleSections() : void {
    $logger = $this->loggerFactory->get('contract_residential_checkups');

    $section_storage = $this->etm->getStorage('contract_sections');

    $query = $section_storage->getQuery()
      ->accessCheck(FALSE);

    $ids = $query->execute();
    if (!$ids) {
      return;
    }

    $queue = $this->queueFactory->get('contract_residential_checkup_generator');

    $count = 0;
    foreach ($ids as $id) {
      $queue->createItem([
        'op' => 'process_section',
        'section_id' => (int) $id,
      ]);
      $count++;
    }

    $logger->info('Dispatched @count contract_sections items for checkup generation.', ['@count' => $count]);
  }

  private function processSection(int $section_id) : void {
    $logger = $this->loggerFactory->get('contract_residential_checkups');

    /** @var \Drupal\Core\Entity\EntityInterface|null $section */
    $section = $this->etm->getStorage('contract_sections')->load($section_id);
    if (!$section) {
      return;
    }

    // Required fields.
    if (!$section->hasField('field_contract') || $section->get('field_contract')->isEmpty()) {
      return;
    }
    if (!$section->hasField('field_service') || $section->get('field_service')->isEmpty()) {
      return;
    }
    if (!$section->hasField('field_check_up_frequency') || $section->get('field_check_up_frequency')->isEmpty()) {
      return;
    }

    // Opt-in (if present).
    if ($section->hasField('field_do_you_want')) {
      $v = strtolower(trim((string) ($section->get('field_do_you_want')->value ?? '')));
      if ($v === '' || $v === 'no' || $v === '0' || $v === 'false') {
        return;
      }
    }

    $contract_id = (int) $section->get('field_contract')->target_id;
    $service_tid = (int) $section->get('field_service')->target_id;
    $freq_tid = (int) $section->get('field_check_up_frequency')->target_id;

    /** @var \Drupal\Core\Entity\EntityInterface|null $contract */
    $contract = $this->etm->getStorage('contracts')->load($contract_id);
    if (!$contract) {
      return;
    }

    // Year gate: only generate for current-year contracts.
    if (!$contract->hasField('field_contract_year') || $contract->get('field_contract_year')->isEmpty()) {
      return;
    }
    $contract_year = substr(trim((string) $contract->get('field_contract_year')->value), 0, 4);
    if ($contract_year !== date('Y')) {
      return;
    }

    // Contract status gate.
    if (!$contract->hasField('field_contract_status') || $contract->get('field_contract_status')->isEmpty()) {
      return;
    }
    $contract_status_tid = (int) $contract->get('field_contract_status')->target_id;
    if (!in_array($contract_status_tid, self::CONTRACT_ALLOWED_STATUS_TIDS, TRUE)) {
      return;
    }

    // Property required.
    if (!$contract->hasField('field_property') || $contract->get('field_property')->isEmpty()) {
      return;
    }
    $property_id = (int) $contract->get('field_property')->target_id;

    /** @var \Drupal\Core\Entity\EntityInterface|null $property */
    $property = $this->etm->getStorage('properties')->load($property_id);
    if (!$property) {
      return;
    }

    // Zipcode route day required.
    if (!$property->hasField('field_zipcode_reference') || $property->get('field_zipcode_reference')->isEmpty()) {
      $logger->warning('Skip section @sid: property @pid missing zipcode reference.', [
        '@sid' => $section_id,
        '@pid' => $property_id,
      ]);
      return;
    }

    $zipcode_id = (int) $property->get('field_zipcode_reference')->target_id;

    /** @var \Drupal\Core\Entity\EntityInterface|null $zipcode */
    $zipcode = $this->etm->getStorage('zipcodes')->load($zipcode_id);
    if (!$zipcode) {
      $logger->warning('Skip section @sid: zipcode entity @zid missing/unloadable.', [
        '@sid' => $section_id,
        '@zid' => $zipcode_id,
      ]);
      return;
    }

    if (!$zipcode->hasField('field_check_up_route_day') || $zipcode->get('field_check_up_route_day')->isEmpty()) {
      $logger->warning('Skip section @sid: zipcode @zid missing checkup route day.', [
        '@sid' => $section_id,
        '@zid' => $zipcode_id,
      ]);
      return;
    }

    $route_day = (int) $zipcode->get('field_check_up_route_day')->value;
    if (!in_array($route_day, self::ROUTE_DAY_ALLOWED, TRUE)) {
      $logger->warning('Skip section @sid: zipcode @zid invalid checkup route day "@d".', [
        '@sid' => $section_id,
        '@zid' => $zipcode_id,
        '@d' => $route_day,
      ]);
      return;
    }

    /** @var \Drupal\taxonomy\TermInterface|null $service_term */
    $service_term = $this->etm->getStorage('taxonomy_term')->load($service_tid);
    if (!$service_term) {
      return;
    }

    // Service must be WO-enabled and map to a valid work_order bundle.
    $wo_flag = (int) ($service_term->get('field_work_order_service')->value ?? 0);
    $wo_bundle = (string) ($service_term->get('field_service_bundle')->value ?? '');
    if ($wo_flag !== 1 || $wo_bundle === '') {
      return;
    }

    $wo_bundles = $this->bundleInfo->getBundleInfo('work_order');
    if (!isset($wo_bundles[$wo_bundle])) {
      $logger->warning('Skip section @sid: service @tid maps to invalid work_order bundle "@bundle".', [
        '@sid' => $section_id,
        '@tid' => $service_tid,
        '@bundle' => $wo_bundle,
      ]);
      return;
    }

    $tz = new \DateTimeZone('America/Denver');
    $today = new DrupalDateTime('now', $tz);
    $horizon_end = (clone $today)->modify('+' . self::HORIZON_DAYS . ' days');

    $due_dates = $this->computeDueDatesWithinHorizon(
      $freq_tid,
      $route_day,
      $today,
      $horizon_end,
      $contract_id,
      $property_id,
      $service_tid
    );

    if (!$due_dates) {
      return;
    }

    foreach ($due_dates as $due_date) {
      if ($this->scheduledWorkOrderExistsForDate($contract_id, $property_id, $service_tid, $due_date)) {
        continue;
      }

      $wo_id = $this->createWorkOrder($wo_bundle, $contract_id, $property_id, $service_tid);
      if ($wo_id <= 0) {
        continue;
      }

      $this->createSchedulingRecord($wo_id, $due_date);

      $logger->info('Created Check Up WO @wo for contract @c property @p service @s due @d.', [
        '@wo' => $wo_id,
        '@c' => $contract_id,
        '@p' => $property_id,
        '@s' => $service_tid,
        '@d' => $due_date->format('Y-m-d'),
      ]);
    }
  }

  private function isWithinIrrigationSeason(DrupalDateTime $date) : bool {
    $year = (int) $date->format('Y');
    $tz = $date->getTimezone();
    $season_start = new DrupalDateTime("$year-05-01", $tz);
    $season_end = new DrupalDateTime("$year-10-15", $tz);
    return ($date >= $season_start && $date <= $season_end);
  }

  private function nextWeekdayOnOrAfter(?DrupalDateTime $start, int $weekday_iso) : DrupalDateTime {
    if (!$start instanceof DrupalDateTime) {
      $start = new DrupalDateTime('now', new \DateTimeZone('America/Denver'));
    }
    $d = clone $start;
    $d->setTime(0, 0, 0);
    $current = (int) $d->format('N');
    $delta = ($weekday_iso - $current + 7) % 7;
    $d->modify('+' . $delta . ' days');
    return $d;
  }

  private function computeDueDatesWithinHorizon(
    int $freq_tid,
    int $route_day_iso,
    DrupalDateTime $today,
    DrupalDateTime $horizon_end,
    int $contract_id,
    int $property_id,
    int $service_tid
  ) : array {
    $dates = [];

    // Weekly.
    if ($freq_tid === self::FREQ_WEEKLY_TID) {
      $cursor = $this->nextWeekdayOnOrAfter($today, $route_day_iso);
      while ($cursor <= $horizon_end) {
        if ($this->isWithinIrrigationSeason($cursor)) {
          $dates[] = clone $cursor;
        }
        $cursor->modify('+7 days');
      }
      return $dates;
    }

    // Biweekly / Monthly.
    if ($freq_tid === self::FREQ_BIWEEKLY_TID || $freq_tid === self::FREQ_MONTHLY_TID) {
      $interval = ($freq_tid === self::FREQ_BIWEEKLY_TID) ? 14 : self::MONTHLY_INTERVAL_DAYS;

      $anchor = $this->mostRecentScheduledDate($contract_id, $property_id, $service_tid);
      if (!$anchor) {
        $anchor = $this->nextWeekdayOnOrAfter($today, $route_day_iso);
      }
      else {
        $safety = 0;
        while ($anchor < $today && $safety < 100) {
          $anchor->modify('+' . $interval . ' days');
          $safety++;
        }
        $anchor = $this->nextWeekdayOnOrAfter($anchor, $route_day_iso);
      }

      $cursor = $anchor;
      while ($cursor <= $horizon_end) {
        if ($this->isWithinIrrigationSeason($cursor)) {
          $dates[] = clone $cursor;
        }
        $cursor->modify('+' . $interval . ' days');
        $cursor = $this->nextWeekdayOnOrAfter($cursor, $route_day_iso);
      }

      return $dates;
    }

    // Mid Season: one occurrence in last week of June through first week of July.
    if ($freq_tid === self::FREQ_MIDSEASON_TID) {
      $tz = $today->getTimezone();
      $year = (int) $today->format('Y');
      $window_start = new DrupalDateTime("$year-06-24", $tz);
      $window_end = new DrupalDateTime("$year-07-07", $tz);

      if ($horizon_end < $window_start || $today > $window_end) {
        return [];
      }

      if ($this->scheduledWorkOrderExistsInRange($contract_id, $property_id, $service_tid, $window_start, $window_end)) {
        return [];
      }

      $start = ($today > $window_start) ? clone $today : clone $window_start;
      $end = ($horizon_end < $window_end) ? clone $horizon_end : clone $window_end;

      $candidate = $this->nextWeekdayOnOrAfter($start, $route_day_iso);
      if ($candidate <= $end && $this->isWithinIrrigationSeason($candidate)) {
        $dates[] = $candidate;
      }

      return $dates;
    }

    return [];
  }

  private function findSchedulingIdsByDateRange(DrupalDateTime $start, DrupalDateTime $end) : array {
    $storage = $this->etm->getStorage('scheduling');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition('type', 'work_order');
    $query->condition('field_date.value', $start->getTimestamp(), '>=');
    $query->condition('field_date.value', $end->getTimestamp(), '<=');

    $ids = $query->execute();
    return $ids ? array_values($ids) : [];
  }

  private function mostRecentScheduledDate(int $contract_id, int $property_id, int $service_tid) : ?DrupalDateTime {
    $tz = new \DateTimeZone('America/Denver');
    $today = new DrupalDateTime('now', $tz);
    $lookback = (clone $today)->modify('-365 days');

    $sched_ids = $this->findSchedulingIdsByDateRange($lookback, $today);
    if (!$sched_ids) {
      return NULL;
    }

    $sched_storage = $this->etm->getStorage('scheduling');
    $wo_storage = $this->etm->getStorage('work_order');

    $latest = NULL;
    $scheds = $sched_storage->loadMultiple($sched_ids);

    foreach ($scheds as $sched) {
      if (!$sched->hasField('field_work_order') || $sched->get('field_work_order')->isEmpty()) {
        continue;
      }

      $wo_id = (int) $sched->get('field_work_order')->target_id;
      if ($wo_id <= 0) {
        continue;
      }

      $wo = $wo_storage->load($wo_id);
      if (!$wo) {
        continue;
      }

      if (!$wo->hasField('field_contract') || (int) ($wo->get('field_contract')->target_id ?? 0) !== $contract_id) {
        continue;
      }
      if (!$wo->hasField('field_property') || (int) ($wo->get('field_property')->target_id ?? 0) !== $property_id) {
        continue;
      }
      if (!$wo->hasField('field_service') || (int) ($wo->get('field_service')->target_id ?? 0) !== $service_tid) {
        continue;
      }

      $date_value = $sched->get('field_date')->value ?? '';
      if ($date_value === '' || $date_value === NULL) {
        continue;
      }

      // field_date is smartdate — value is a Unix timestamp.
      if (is_numeric($date_value)) {
        $dt = DrupalDateTime::createFromTimestamp((int) $date_value, $tz);
      }
      else {
        $dt = new DrupalDateTime(substr((string) $date_value, 0, 10), $tz);
      }
      if (!$latest || $dt > $latest) {
        $latest = $dt;
      }
    }

    return $latest;
  }

  private function scheduledWorkOrderExistsForDate(int $contract_id, int $property_id, int $service_tid, DrupalDateTime $due_date) : bool {
    $start = (clone $due_date)->setTime(0, 0, 0);
    $end = (clone $due_date)->setTime(23, 59, 59);

    $sched_ids = $this->findSchedulingIdsByDateRange($start, $end);
    if (!$sched_ids) {
      return FALSE;
    }

    $sched_storage = $this->etm->getStorage('scheduling');
    $wo_storage = $this->etm->getStorage('work_order');
    $scheds = $sched_storage->loadMultiple($sched_ids);

    foreach ($scheds as $sched) {
      if (!$sched->hasField('field_work_order') || $sched->get('field_work_order')->isEmpty()) {
        continue;
      }

      $wo_id = (int) $sched->get('field_work_order')->target_id;
      if ($wo_id <= 0) {
        continue;
      }

      $wo = $wo_storage->load($wo_id);
      if (!$wo) {
        continue;
      }

      if (!$wo->hasField('field_contract') || (int) ($wo->get('field_contract')->target_id ?? 0) !== $contract_id) {
        continue;
      }
      if (!$wo->hasField('field_property') || (int) ($wo->get('field_property')->target_id ?? 0) !== $property_id) {
        continue;
      }
      if (!$wo->hasField('field_service') || (int) ($wo->get('field_service')->target_id ?? 0) !== $service_tid) {
        continue;
      }

      return TRUE;
    }

    return FALSE;
  }

  private function scheduledWorkOrderExistsInRange(int $contract_id, int $property_id, int $service_tid, DrupalDateTime $start, DrupalDateTime $end) : bool {
    $sched_ids = $this->findSchedulingIdsByDateRange($start, $end);
    if (!$sched_ids) {
      return FALSE;
    }

    $sched_storage = $this->etm->getStorage('scheduling');
    $wo_storage = $this->etm->getStorage('work_order');
    $scheds = $sched_storage->loadMultiple($sched_ids);

    foreach ($scheds as $sched) {
      if (!$sched->hasField('field_work_order') || $sched->get('field_work_order')->isEmpty()) {
        continue;
      }

      $wo_id = (int) $sched->get('field_work_order')->target_id;
      if ($wo_id <= 0) {
        continue;
      }

      $wo = $wo_storage->load($wo_id);
      if (!$wo) {
        continue;
      }

      if (!$wo->hasField('field_contract') || (int) ($wo->get('field_contract')->target_id ?? 0) !== $contract_id) {
        continue;
      }
      if (!$wo->hasField('field_property') || (int) ($wo->get('field_property')->target_id ?? 0) !== $property_id) {
        continue;
      }
      if (!$wo->hasField('field_service') || (int) ($wo->get('field_service')->target_id ?? 0) !== $service_tid) {
        continue;
      }

      return TRUE;
    }

    return FALSE;
  }

  private function createWorkOrder(string $bundle, int $contract_id, int $property_id, int $service_tid) : int {
    $wo_storage = $this->etm->getStorage('work_order');

    $wo = $wo_storage->create([
      'type' => $bundle,
      'uid' => 1,
      'created' => $this->time->getRequestTime(),
      'field_contract' => $contract_id,
      'field_property' => $property_id,
      'field_service' => $service_tid,
      'field_status' => self::WO_STATUS_OPEN_TID,
      'field_invoiced' => 0,
      'field_scheduled' => 1,
    ]);

    $wo->save();
    // AEL pattern for sprinkler_check_up uses [work_order:id], not
    // assigned during presave on insert — first save writes the AEL
    // placeholder. Clear and save again so AEL regenerates the title
    // with the now-known id. See drupal_bos_gotchas.md.
    $wo->set('title', '');
    $wo->save();
    return (int) $wo->id();
  }

  private function createSchedulingRecord(int $work_order_id, DrupalDateTime $due_date) : void {
    $sched_storage = $this->etm->getStorage('scheduling');

    $date_str = $due_date->format('Y-m-d');
    $sched = $sched_storage->create([
      'type' => 'work_order',
      'title' => 'Scheduled',
      'uid' => 1,
      'created' => $this->time->getRequestTime(),
      'field_work_order' => $work_order_id,
      'field_date' => [
        'value' => $due_date->getTimestamp(),
        'end_value' => $due_date->getTimestamp(),
        'duration' => 1439,
        'all_day' => TRUE,
      ],
      'field_scheduled_date_and_time' => [
        'value' => $date_str,
        'end_value' => $date_str,
        'all_day' => TRUE,
      ],
      'field_scheduled' => 1,
      'field_scheduled_firm' => 0,
    ]);

    $sched->save();
  }

}
