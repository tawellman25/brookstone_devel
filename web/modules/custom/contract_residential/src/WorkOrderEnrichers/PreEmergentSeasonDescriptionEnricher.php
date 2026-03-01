<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Pre-emergent enricher: set season + service-specific description.
 *
 * Rules:
 * - Pre-emergent is NEVER multistage.
 * - If WO field_pre_emergent_season is empty:
 *   - copy from section field_pre_emergent_season if present,
 *   - else default to FALL (tid=1102).
 * - Description overwrite eligibility governed by DescriptionOverrideHelper:
 *   - overwrite if empty OR token-default OR generator fallback.
 * - Year comes from contracts.field_contract_year.
 */
final class PreEmergentSeasonDescriptionEnricher implements EnricherInterface {

  private const WO_BUNDLE = 'pre_emergent';

  // Seasons tids (shared Seasons vocabulary).
  private const TID_SPRING = 1100;
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

    // 1) Ensure season is set.
    $season_tid = NULL;
    $season_label = NULL;

    if ($work_order->hasField('field_pre_emergent_season')) {
      if (!$work_order->get('field_pre_emergent_season')->isEmpty()) {
        $season_tid = (int) $work_order->get('field_pre_emergent_season')->target_id;
      }
      else {
        // Prefer section season if present.
        if ($section->hasField('field_pre_emergent_season') && !$section->get('field_pre_emergent_season')->isEmpty()) {
          $work_order->set('field_pre_emergent_season', $section->get('field_pre_emergent_season')->getValue());
          $season_tid = (int) $section->get('field_pre_emergent_season')->target_id;
        }
        else {
          // Authoritative default when section season is empty: FALL.
          $work_order->set('field_pre_emergent_season', ['target_id' => self::TID_FALL]);
          $season_tid = self::TID_FALL;
        }
      }
    }

    if ($season_tid !== NULL && $season_tid > 0) {
      $term = $this->etm->getStorage('taxonomy_term')->load($season_tid);
      if ($term instanceof TermInterface) {
        $season_label = (string) $term->label();
      }
    }

    // 2) Description (overwrite only if eligible).
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

    // Keep the wording consistent and BOS-friendly.
    $season_part = ($season_label !== NULL && $season_label !== '') ? " — {$season_label} " : ' — ';
    $html = "<p>{$prefix}Pre-emergent{$season_part}as needed.<br><br>See Contract #{$contract_id} for details.</p>";

    $work_order->set('field_work_todo_description', [
      'value' => $html,
      'format' => 'full_html',
    ]);
  }

}
