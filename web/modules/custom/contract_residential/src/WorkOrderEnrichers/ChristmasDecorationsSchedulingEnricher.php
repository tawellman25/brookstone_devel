<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Christmas decorations scheduling enricher (stage-aware, idempotent, wo_schedule-compatible).
 *
 * Authoritative behavior:
 * - Runs post-save only (needs WO id).
 * - Stage via context['pointer_field'].
 * - Creates exactly one scheduling:work_order per WO if none exists.
 * - Never overwrites existing schedule.
 * - Uses contracts.field_contract_year (not current date).
 *
 * IMPORTANT:
 * - We set ONLY scheduling.field_date (SmartDate) using UNIX timestamps.
 * - wo_schedule_entity_presave() will sync field_date -> field_scheduled_date_and_time.
 * - Never throw; scheduling must never break WO generation.
 */
final class ChristmasDecorationsSchedulingEnricher implements EnricherInterface {

  private const WO_BUNDLE = 'christmas_decorations';
  private const TZ = 'America/Denver';

  private EntityTypeManagerInterface $etm;
  private LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->etm = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  public function apply(
    EntityInterface $contract,
    EntityInterface $section,
    TermInterface $service,
    EntityInterface $work_order,
    array $context,
    WorkOrderGenerationResult $result,
    array $options
  ): void {
    if (($options['enricher_phase'] ?? 'pre_save') !== 'post_save') {
      return;
    }

    if ((string) $work_order->bundle() !== self::WO_BUNDLE) {
      return;
    }

    $wo_id = (int) $work_order->id();
    if ($wo_id <= 0) {
      return;
    }

    try {
      if (!$contract->hasField('field_contract_year') || $contract->get('field_contract_year')->isEmpty()) {
        $this->loggerFactory->get('contract_residential')->warning(
          'Christmas scheduling skipped: Contract @cid missing field_contract_year.',
          ['@cid' => (int) $contract->id()]
        );
        return;
      }

      $year = (int) trim((string) $contract->get('field_contract_year')->value);
      if ($year <= 0) {
        $this->loggerFactory->get('contract_residential')->warning(
          'Christmas scheduling skipped: Contract @cid has invalid field_contract_year value.',
          ['@cid' => (int) $contract->id()]
        );
        return;
      }

      $pointer_field = (string) ($context['pointer_field'] ?? '');

      // Match wo_schedule conventions: America/Denver at 07:00 local.
      $dt_string = ($pointer_field === 'field_2nd_work_order')
        ? sprintf('%d-01-03 07:00:00', $year + 1)  // Take Down
        : sprintf('%d-11-15 07:00:00', $year);      // Hang

      $tz = new \DateTimeZone(self::TZ);
      $dt = new DrupalDateTime($dt_string, $tz);

      // Idempotent: do nothing if schedule exists for this WO.
      $sched_storage = $this->etm->getStorage('scheduling');
      $existing_ids = $sched_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'work_order')
        ->condition('field_work_order.target_id', $wo_id)
        ->range(0, 1)
        ->execute();

      if (!empty($existing_ids)) {
        return;
      }

      /** @var \Drupal\Core\Entity\EntityInterface $sched */
      $sched = $sched_storage->create([
        'type' => 'work_order',
        'field_work_order' => ['target_id' => $wo_id],
      ]);

      // SmartDate expects UNIX timestamps (UTC). wo_schedule presave uses them.
      $start_ts = $dt->getTimestamp();         // epoch seconds
      $end_ts = $start_ts + 86400 - 1;         // 1-day window like existing records
      $duration = '1440';

      if ($sched->hasField('field_date')) {
        $sched->set('field_date', [
          'value' => (string) $start_ts,
          'end_value' => (string) $end_ts,
          'duration' => $duration,
          'rrule' => NULL,
          'rrule_index' => NULL,
          'timezone' => self::TZ,
        ]);
      }
      else {
        // If field_date doesn't exist, we cannot safely schedule using wo_schedule conventions.
        $this->loggerFactory->get('contract_residential')->warning(
          'Christmas scheduling skipped: scheduling:work_order missing field_date (SmartDate).'
        );
        return;
      }

      if ($sched->hasField('field_scheduled')) {
        $sched->set('field_scheduled', TRUE);
      }
      if ($sched->hasField('field_scheduled_firm')) {
        $sched->set('field_scheduled_firm', FALSE);
      }
      // Note: your field name is misspelled in schema.
      if ($sched->hasField('field_scheduled_oder')) {
        $sched->set('field_scheduled_oder', 10);
      }
      if ($sched->hasField('field_scheduling_note')) {
        $sched->set('field_scheduling_note', 'Automatically Scheduled by System');
      }

      // wo_schedule_entity_presave() will sync field_date -> field_scheduled_date_and_time.
      $sched->save();
    }
    catch (\Throwable $e) {
      // Never allow scheduling to break WO generation.
      $this->loggerFactory->get('contract_residential')->error(
        'Christmas scheduling error (ignored) for WO @wo: @msg',
        ['@wo' => $wo_id, '@msg' => $e->getMessage()]
      );
    }
  }

}
