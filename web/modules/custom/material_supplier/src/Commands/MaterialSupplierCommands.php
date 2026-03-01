<?php

declare(strict_types=1);

namespace Drupal\material_supplier\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;

final class MaterialSupplierCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Audit Material ↔ Supplier link records for common data issues.
   *
   * @command material-supplier:audit
   * @aliases ms-audit
   * @usage material-supplier:audit
   */
  public function audit(): int {
    $storage = $this->entityTypeManager->getStorage('material_suppliers');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'supplier')
      ->execute();

    if (empty($ids)) {
      $this->output()->writeln('No material_suppliers:supplier records found.');
      return 0;
    }

    $entities = $storage->loadMultiple($ids);

    $duplicates = [];
    $seen = []; // key => first_id
    $missing_pack = [];
    $suspicious_sku = [];

    foreach ($entities as $e) {
      $id = (int) $e->id();
      $material = (int) ($e->hasField('field_material') ? ($e->get('field_material')->target_id ?? 0) : 0);
      $supplier = (int) ($e->hasField('field_supplier') ? ($e->get('field_supplier')->target_id ?? 0) : 0);

      // Duplicates.
      if ($material > 0 && $supplier > 0) {
        $key = $material . ':' . $supplier;
        if (isset($seen[$key])) {
          $duplicates[] = [$id, $seen[$key], $material, $supplier];
        }
        else {
          $seen[$key] = $id;
        }
      }

      // Missing pack quantity when Order UOM != Cost UOM.
      if ($e->hasField('field_order_uom') && $e->hasField('field_cost_uom') && $e->hasField('field_pack_quantity')) {
        $order_uom = trim((string) ($e->get('field_order_uom')->value ?? ''));
        $cost_uom = trim((string) ($e->get('field_cost_uom')->value ?? ''));
        if ($order_uom !== '' && $cost_uom !== '' && $order_uom !== $cost_uom) {
          $pack_qty = (int) ($e->get('field_pack_quantity')->value ?? 0);
          if ($pack_qty <= 0) {
            $missing_pack[] = [$id, $material, $supplier, $order_uom, $cost_uom];
          }
        }
      }

      // Suspicious SKU.
      if ($e->hasField('field_supplier_item_number')) {
        $sku = trim((string) ($e->get('field_supplier_item_number')->value ?? ''));
        if ($sku !== '') {
          $lower = strtolower($sku);

          $looks_like_url =
            str_contains($lower, 'http://') ||
            str_contains($lower, 'https://') ||
            str_contains($lower, 'www.') ||
            str_contains($lower, '/product/') ||
            str_contains($lower, '?') ||
            str_contains($lower, '#');

          $looks_like_email = str_contains($lower, '@');
          $looks_like_description = (str_contains($sku, ' ') && strlen($sku) > 25);

          if ($looks_like_url || $looks_like_email || $looks_like_description) {
            $suspicious_sku[] = [$id, $material, $supplier, $sku];
          }
        }
      }
    }

    $table = new Table($this->output());
    $table->setHeaders(['Audit Category', 'Details']);

    $rows = [];
    $rows[] = ['Duplicates', $duplicates ? count($duplicates) . ' found' : 'None'];
    if ($duplicates) {
      $rows[] = new TableSeparator();
      $rows[] = ['Duplicate pairs', 'id | first_id | material_id | supplier_id'];
      foreach ($duplicates as $d) {
        $rows[] = ['', implode(' | ', array_map('strval', $d))];
      }
    }

    $rows[] = new TableSeparator();
    $rows[] = ['Missing pack qty (Order UOM != Cost UOM)', $missing_pack ? count($missing_pack) . ' found' : 'None'];
    if ($missing_pack) {
      $rows[] = new TableSeparator();
      $rows[] = ['Records', 'id | material_id | supplier_id | order_uom | cost_uom'];
      foreach ($missing_pack as $m) {
        $rows[] = ['', implode(' | ', array_map('strval', $m))];
      }
    }

    $rows[] = new TableSeparator();
    $rows[] = ['Suspicious Supplier Item #', $suspicious_sku ? count($suspicious_sku) . ' found' : 'None'];
    if ($suspicious_sku) {
      $rows[] = new TableSeparator();
      $rows[] = ['Records', 'id | material_id | supplier_id | value'];
      foreach ($suspicious_sku as $s) {
        $val = (string) $s[3];
        if (strlen($val) > 80) {
          $val = substr($val, 0, 77) . '...';
        }
        $rows[] = ['', $s[0] . ' | ' . $s[1] . ' | ' . $s[2] . ' | ' . $val];
      }
    }

    $table->setRows($rows);
    $table->render();

    return 0;
  }

}
