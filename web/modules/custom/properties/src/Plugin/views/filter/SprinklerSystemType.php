<?php

declare(strict_types=1);

namespace Drupal\properties\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter sprinkler work orders by water source / system type.
 *
 * `property_sprinkler_system.field_system_type` references the
 * `sprinkler_system_types` ECK entity — Domestic / Dirty / Duel / Well Water
 * System — which the office calls the water source type, and which winterizing
 * and start-up bill the pump fee from.
 *
 * Why a custom plugin rather than something core ships:
 * - Views data declares a **numeric** filter for the target_id column, so out of
 *   the box the office gets a box to type an entity id into.
 * - Core's `entity_reference` filter was tried first and **does not filter** in
 *   this install: the dropdown renders correctly and every selection returns the
 *   unfiltered result. It defers to `$this->validatedExposedInput`, populated only
 *   in `validateExposed()`, and that value does not reach the handler that builds
 *   the query here. Measured over HTTP, not inferred — all four types returned an
 *   identical 963 rows.
 * - `InOperator` is the path already proven in this same view: the Status and
 *   Complexity filters are `taxonomy_index_tid`, which is `InOperator`-based, and
 *   they work.
 *
 * Options come from the entity, so a new system type appears in the dropdown with
 * no code change.
 */
#[ViewsFilter("properties_sprinkler_system_type")]
class SprinklerSystemType extends InOperator {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): array {
    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }
    $this->valueOptions = [];
    try {
      $storage = $this->entityTypeManager->getStorage('sprinkler_system_types');
    }
    catch (\Throwable $e) {
      // Entity type gone: render an empty list rather than breaking the view.
      return $this->valueOptions;
    }
    $types = $storage->loadMultiple();
    foreach ($types as $type) {
      $this->valueOptions[(int) $type->id()] = $type->label();
    }
    // Stable, readable order — the office reads these as a list, not by id.
    natcasesort($this->valueOptions);
    return $this->valueOptions;
  }

}
