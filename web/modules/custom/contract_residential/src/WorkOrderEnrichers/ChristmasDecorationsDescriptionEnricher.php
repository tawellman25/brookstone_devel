<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Christmas decorations description enricher (stage-aware).
 *
 * - Targets work_order bundle 'christmas_decorations'.
 * - Overwrite eligibility governed by DescriptionOverrideHelper:
 *   - empty OR token-default OR generator fallback.
 * - Writes stage-specific text:
 *   - field_work_order => Hang Christmas Lights and Decorations
 *   - field_2nd_work_order => Take Down Christmas Lights and Decorations
 */
final class ChristmasDecorationsDescriptionEnricher implements EnricherInterface {

  private const WO_BUNDLE = 'christmas_decorations';

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

    if (!DescriptionOverrideHelper::isOverwriteEligible($work_order, $contract, $service)) {
      return;
    }

    if (!$work_order->hasField('field_work_todo_description')) {
      return;
    }

    $pointer_field = (string) ($context['pointer_field'] ?? '');

    $stage_label = 'Christmas Decorations';
    if ($pointer_field === 'field_2nd_work_order') {
      $stage_label = 'Take Down Christmas Lights and Decorations';
    }
    else {
      // Default stage.
      $stage_label = 'Hang Christmas Lights and Decorations';
    }

    $contract_id = (int) $contract->id();

    $year = ($contract->hasField('field_contract_year') && !$contract->get('field_contract_year')->isEmpty())
      ? trim((string) $contract->get('field_contract_year')->value)
      : '';
    $prefix = ($year !== '') ? "{$year} — " : '';

    // New standardized format (line break + details line).
    $html = "<p>{$prefix}{$stage_label} as needed.<br><br>See Contract #{$contract_id} for details.</p>";

    $work_order->set('field_work_todo_description', [
      'value' => $html,
      'format' => 'full_html',
    ]);
  }

}
