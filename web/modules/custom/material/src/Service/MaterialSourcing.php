<?php

namespace Drupal\material\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Read-only sourcing helper for material_suppliers.
 *
 * Centralizes effective status logic so it isn't duplicated in Views/UI.
 * Must never mutate Work Orders or snapshot values.
 */
final class MaterialSourcing {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Effective status resolution:
   * - If link override is set and not 'inherit', use it.
   * - Else fallback to supplier.field_supplier_status (if present).
   *
   * @return string|null
   *   active|limited|do_not_use|inherit|null
   */
  public function getEffectiveSupplierStatus(EntityInterface $materialSupplierLink): ?string {
    if ($materialSupplierLink->getEntityTypeId() !== 'material_suppliers') {
      return NULL;
    }

    // 1) Link override.
    if ($materialSupplierLink->hasField('field_supplier_status_override') && !$materialSupplierLink->get('field_supplier_status_override')->isEmpty()) {
      $override = (string) $materialSupplierLink->get('field_supplier_status_override')->value;
      if ($override && $override !== 'inherit') {
        return $override;
      }
      // Explicit inherit means "fall through".
    }

    // 2) Supplier master status.
    if (!$materialSupplierLink->hasField('field_supplier') || $materialSupplierLink->get('field_supplier')->isEmpty()) {
      return NULL;
    }

    $supplier = $materialSupplierLink->get('field_supplier')->entity;
    if (!$supplier) {
      return NULL;
    }

    if ($supplier->hasField('field_supplier_status') && !$supplier->get('field_supplier_status')->isEmpty()) {
      return (string) $supplier->get('field_supplier_status')->value;
    }

    return NULL;
  }

}
