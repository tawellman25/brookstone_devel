<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\taxonomy\TermInterface;

final class EnricherManager {

  /**
   * @var \Drupal\contract_residential\WorkOrderEnrichers\EnricherInterface[]
   */
  private array $enrichers;

  /**
   * @param iterable<\Drupal\contract_residential\WorkOrderEnrichers\EnricherInterface> $enrichers
   */
  public function __construct(iterable $enrichers = []) {
    $this->enrichers = [];
    foreach ($enrichers as $enricher) {
      $this->enrichers[] = $enricher;
    }
  }

  public function applyAll(
    EntityInterface $contract,
    EntityInterface $section,
    TermInterface $service,
    EntityInterface $work_order,
    array $context,
    WorkOrderGenerationResult $result,
    array $options
  ): void {
    foreach ($this->enrichers as $enricher) {
      try {
        $enricher->apply($contract, $section, $service, $work_order, $context, $result, $options);
      }
      catch (\Throwable $e) {
        $sid = (int) $section->id();
        $result->addMessage("ERROR: Section {$sid}: enricher " . get_class($enricher) . " failed (" . $e->getMessage() . ").");
      }
    }
  }

}
