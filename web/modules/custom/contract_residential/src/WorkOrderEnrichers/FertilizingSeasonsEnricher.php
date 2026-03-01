<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Fertilizing enricher:
 * - Sets field_fertilizing_season (list_string) based on stage and section season selection.
 * - Sets a stage-specific description when overwrite-eligible.
 *
 * Season model (authoritative):
 * - Contract Section: field_fertilizer_app_season (taxonomy term refs in Seasons vocab)
 *   - Pre-season tid=1327
 *   - Spring tid=1100
 *   - Summer tid=1101
 *   - Fall tid=1102
 * - Work Order: field_fertilizing_season (list_string): pre|spring|summer|fall
 *
 * Overwrite rules:
 * - Uses DescriptionOverrideHelper to allow overwrite of empty/token-default/generator fallback only.
 */
final class FertilizingSeasonsEnricher implements EnricherInterface {

  private const WO_BUNDLE = 'fertilizing';

  // Seasons tids (shared Seasons vocabulary).
  private const TID_PRE = 1327;
  private const TID_SPRING = 1100;
  private const TID_SUMMER = 1101;
  private const TID_FALL = 1102;

  private EntityTypeManagerInterface $etm;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->etm = $entity_type_manager;
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
    // Pre-save only.
    if (($options['enricher_phase'] ?? 'pre_save') === 'post_save') {
      return;
    }

    if ((string) $work_order->bundle() !== self::WO_BUNDLE) {
      return;
    }

    // Determine stage index by pointer field.
    $pointer_field = (string) ($context['pointer_field'] ?? 'field_work_order');
    $stage_index = $this->pointerFieldToIndex($pointer_field);
    if ($stage_index === NULL) {
      return;
    }

    // Build ordered season keys based on section selection.
    $ordered_keys = $this->getOrderedSeasonKeysFromSection($section);
    if (empty($ordered_keys) || !isset($ordered_keys[$stage_index])) {
      return;
    }

    $season_key = $ordered_keys[$stage_index]; // pre|spring|summer|fall

    // 1) Set field_fertilizing_season if empty.
    if ($work_order->hasField('field_fertilizing_season') && $work_order->get('field_fertilizing_season')->isEmpty()) {
      $work_order->set('field_fertilizing_season', $season_key);
      $result->addMessage("Section {$section->id()}: set field_fertilizing_season='{$season_key}' for fertilizing WO stage ({$pointer_field}).");
    }

    // 2) Set stage-specific description if overwrite-eligible.
    if (!DescriptionOverrideHelper::isOverwriteEligible($work_order, $contract, $service)) {
      return;
    }

    if (!$work_order->hasField('field_work_todo_description')) {
      return;
    }

    $contract_id = (int) $contract->id();

    $year = ($contract->hasField('field_contract_year') && !$contract->get('field_contract_year')->isEmpty())
      ? trim((string) $contract->get('field_contract_year')->value)
      : '';
    $prefix = ($year !== '') ? "{$year} — " : '';

    $service_label = trim((string) $service->label());
    if ($service_label === '') {
      $service_label = 'Fertilizing';
    }

    $stage_label = $this->seasonKeyToStageLabel($season_key);

    $html = "<p>{$prefix}{$service_label} — {$stage_label} Application as needed.<br><br>See Contract #{$contract_id} for details.</p>";

    $work_order->set('field_work_todo_description', [
      'value' => $html,
      'format' => 'full_html',
    ]);
  }

  /**
   * Convert pointer field to stage index.
   */
  private function pointerFieldToIndex(string $pointer_field): ?int {
    return match ($pointer_field) {
      'field_work_order' => 0,
      'field_2nd_work_order' => 1,
      'field_3rd_work_order' => 2,
      'field_4th_work_order' => 3,
      default => NULL,
    };
  }

  /**
   * Determine fertilizing season keys in order, based on section selection.
   *
   * If section selection is empty, default intent is Spring + Summer + Fall.
   * If selection is present, we respect it and order by: pre, spring, summer, fall.
   *
   * @return string[] season keys in stage order.
   */
  private function getOrderedSeasonKeysFromSection(EntityInterface $section): array {
    // Default when empty selection: Spring + Summer + Fall.
    if (!$section->hasField('field_fertilizer_app_season') || $section->get('field_fertilizer_app_season')->isEmpty()) {
      return ['spring', 'summer', 'fall'];
    }

    $tids = [];
    foreach ($section->get('field_fertilizer_app_season')->getValue() as $item) {
      if (!empty($item['target_id'])) {
        $tids[] = (int) $item['target_id'];
      }
    }
    $tids = array_values(array_unique(array_filter($tids)));

    $keys = [];
    foreach ([self::TID_PRE => 'pre', self::TID_SPRING => 'spring', self::TID_SUMMER => 'summer', self::TID_FALL => 'fall'] as $tid => $key) {
      if (in_array($tid, $tids, TRUE)) {
        $keys[] = $key;
      }
    }

    // If selection list was somehow non-empty but none matched our known tids, fail closed.
    return $keys;
  }

  private function seasonKeyToStageLabel(string $season_key): string {
    return match ($season_key) {
      'pre' => 'Pre-season',
      'spring' => 'Spring',
      'summer' => 'Summer',
      'fall' => 'Fall',
      default => ucfirst($season_key),
    };
  }

}
