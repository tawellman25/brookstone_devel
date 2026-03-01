<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Aeration enricher: set season + service-specific description.
 *
 * Behavior:
 * - Targets work_order bundle 'aerating'.
 * - Sets field_aeration_season if empty:
 *   - copy from section field_aeration_season if present,
 *   - else default to Spring (shared Seasons vocabulary).
 * - Description overwrite eligibility is governed by DescriptionOverrideHelper:
 *   - overwrite if empty OR token-default OR generator fallback.
 * - Writes aeration-specific description format.
 */
final class AerationSeasonDescriptionEnricher implements EnricherInterface {

  private const WO_BUNDLE = 'aerating';
  private const DEFAULT_SEASON_NAME = 'Spring';

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

    if ($work_order->hasField('field_aeration_season')) {
      if (!$work_order->get('field_aeration_season')->isEmpty()) {
        $season_tid = (int) $work_order->get('field_aeration_season')->target_id;
      }
      else {
        // Prefer section-provided season.
        if ($section->hasField('field_aeration_season') && !$section->get('field_aeration_season')->isEmpty()) {
          $work_order->set('field_aeration_season', $section->get('field_aeration_season')->getValue());
          $season_tid = (int) $section->get('field_aeration_season')->target_id;
        }
        else {
          // Default to Spring.
          $tid = $this->resolveSeasonTermIdByName($work_order, 'field_aeration_season', self::DEFAULT_SEASON_NAME);
          if ($tid !== NULL) {
            $work_order->set('field_aeration_season', ['target_id' => $tid]);
            $season_tid = $tid;
          }
          else {
            // Even if we couldn't set the ref, we can still use the label in description.
            $season_label = self::DEFAULT_SEASON_NAME;
          }
        }
      }
    }

    if ($season_tid !== NULL && $season_tid > 0) {
      $term = $this->etm->getStorage('taxonomy_term')->load($season_tid);
      if ($term instanceof TermInterface) {
        $season_label = (string) $term->label();
      }
    }

    // 2) Service-specific description.
    if (!DescriptionOverrideHelper::isOverwriteEligible($work_order, $contract, $service)) {
      return;
    }

    if (!$work_order->hasField('field_work_todo_description')) {
      return;
    }

    $contract_id = (int) $contract->id();

    $year = '';
    if ($contract->hasField('field_contract_year') && !$contract->get('field_contract_year')->isEmpty()) {
      $year = trim((string) $contract->get('field_contract_year')->value);
    }
    $prefix = ($year !== '') ? "{$year} — " : '';

    $season_part = ($season_label !== NULL && $season_label !== '') ? " — {$season_label} " : ' — ';
    $html = "<p>{$prefix}Aeration{$season_part}as needed.<br><br>See Contract #{$contract_id} for details.</p>";

    $work_order->set('field_work_todo_description', [
      'value' => $html,
      'format' => 'full_html',
    ]);
  }

  /**
   * Resolve a season term ID by name, respecting field vocabulary restrictions when present.
   */
  private function resolveSeasonTermIdByName(EntityInterface $entity, string $field_name, string $season_name): ?int {
    if (!$entity->hasField($field_name)) {
      return NULL;
    }

    $definition = $entity->getFieldDefinition($field_name);
    $settings = $definition->getSettings();
    $handler_settings = $settings['handler_settings'] ?? [];
    $target_bundles = array_keys($handler_settings['target_bundles'] ?? []);

    $q = $this->etm->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $season_name);

    if (!empty($target_bundles)) {
      $q->condition('vid', $target_bundles, 'IN');
    }

    $ids = $q->range(0, 1)->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

}
