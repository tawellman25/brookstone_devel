<?php

declare(strict_types=1);

namespace Drupal\estimate_items\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns material cost as JSON for estimate_items form auto-population.
 */
class MaterialCostController extends ControllerBase {

  /**
   * Returns the cost of a material entity.
   */
  public function getCost(string $material_id): JsonResponse {
    $material = $this->entityTypeManager()->getStorage('material')->load($material_id);

    if (!$material || !$material->hasField('field_cost_integer') || $material->get('field_cost_integer')->isEmpty()) {
      return new JsonResponse(['cost' => 0]);
    }

    return new JsonResponse(['cost' => (float) $material->get('field_cost_integer')->value]);
  }

}
