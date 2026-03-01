<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

final class GenericServiceDescriptionEnricher implements EnricherInterface {

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

    if (!$work_order->hasField('field_work_todo_description')) {
      return;
    }

    if (!DescriptionOverrideHelper::isOverwriteEligible($work_order, $contract, $service)) {
      return;
    }

    $contract_id = (int) $contract->id();

    $year = ($contract->hasField('field_contract_year') && !$contract->get('field_contract_year')->isEmpty())
      ? trim((string) $contract->get('field_contract_year')->value)
      : '';
    $prefix = ($year !== '') ? "{$year} — " : '';

    $service_label = trim((string) $service->label());
    if ($service_label === '') {
      $service_label = 'Service';
    }

    $season_label = $this->detectSeasonLabelOnWorkOrder($work_order);
    $season_part = ($season_label !== NULL && $season_label !== '') ? " — {$season_label}" : '';

    $html = "<p>{$prefix}{$service_label}{$season_part} as needed.<br><br>See Contract #{$contract_id} for details.</p><!-- bos:auto -->";

    $work_order->set('field_work_todo_description', [
      'value' => $html,
      'format' => 'full_html',
    ]);
  }

  private function detectSeasonLabelOnWorkOrder(EntityInterface $work_order): ?string {
    foreach ($work_order->getFieldDefinitions() as $field_name => $def) {
      if (strpos($field_name, 'field_') !== 0) {
        continue;
      }
      if (!str_ends_with($field_name, '_season')) {
        continue;
      }
      if ($def->getType() !== 'entity_reference') {
        continue;
      }
      if (!$work_order->hasField($field_name) || $work_order->get($field_name)->isEmpty()) {
        continue;
      }
      $tid = (int) $work_order->get($field_name)->target_id;
      if ($tid <= 0) {
        continue;
      }
      $term = $this->etm->getStorage('taxonomy_term')->load($tid);
      return ($term instanceof TermInterface) ? (string) $term->label() : NULL;
    }
    return NULL;
  }

}
