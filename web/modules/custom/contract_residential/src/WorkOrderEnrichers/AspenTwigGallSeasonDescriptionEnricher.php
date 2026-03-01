<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Aspen Twig Gall enricher: set season + service-specific description.
 *
 * - Targets work_order bundle 'aspen_twig_gall'.
 * - Sets field_aspen_twig_gall_season if empty:
 *   - copy from section if present,
 *   - else default to Spring (shared Seasons vocabulary).
 * - Description overwrite eligibility is governed by DescriptionOverrideHelper:
 *   - overwrite if empty OR token-default OR generator fallback.
 */
final class AspenTwigGallSeasonDescriptionEnricher implements EnricherInterface {

  private const WO_BUNDLE = 'aspen_twig_gall';
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

    // 1) Ensure season is set (if field exists).
    $season_tid = NULL;
    $season_label = NULL;

    if ($work_order->hasField('field_aspen_twig_gall_season')) {
      if (!$work_order->get('field_aspen_twig_gall_season')->isEmpty()) {
        $season_tid = (int) $work_order->get('field_aspen_twig_gall_season')->target_id;
      }
      else {
        if ($section->hasField('field_aspen_twig_gall_season') && !$section->get('field_aspen_twig_gall_season')->isEmpty()) {
          $work_order->set('field_aspen_twig_gall_season', $section->get('field_aspen_twig_gall_season')->getValue());
          $season_tid = (int) $section->get('field_aspen_twig_gall_season')->target_id;
        }
        else {
          $tid = $this->resolveSeasonTermIdByName($work_order, 'field_aspen_twig_gall_season', self::DEFAULT_SEASON_NAME);
          if ($tid !== NULL) {
            $work_order->set('field_aspen_twig_gall_season', ['target_id' => $tid]);
            $season_tid = $tid;
          }
          else {
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

    // 2) Description (only if eligible).
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

    $service_label = trim((string) $service->label());
    $service_part = ($service_label !== '') ? $service_label : 'Aspen Twig Gall Control';

    $season_part = ($season_label !== NULL && $season_label !== '') ? " — {$season_label} " : ' — ';
    $html = "<p>{$prefix}{$service_part}{$season_part}as needed.<br><br>See Contract #{$contract_id} for details.</p>";

    $work_order->set('field_work_todo_description', [
      'value' => $html,
      'format' => 'full_html',
    ]);
  }

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
