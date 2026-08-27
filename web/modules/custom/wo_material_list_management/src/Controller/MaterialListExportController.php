<?php

declare(strict_types=1);

namespace Drupal\wo_material_list_management\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\eck\Entity\EckEntity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export a material list's items to CSV, and a blank import template.
 */
final class MaterialListExportController extends ControllerBase {

  /**
   * Export the current list's items as CSV (re-importable).
   */
  public function export(EckEntity $wo_material_list): Response {
    $storage = $this->entityTypeManager()->getStorage('wo_material_list_item');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_list_id', $wo_material_list->id())->sort('id')->execute();

    $rows = [['identifier', 'material_name', 'supplier_item_number', 'quantity', 'unit_cost']];
    foreach ($storage->loadMultiple($ids) as $item) {
      $mid = $item->get('field_parts_used')->target_id;
      $material = $mid ? $this->entityTypeManager()->getStorage('material')->load($mid) : NULL;
      $rows[] = [
        $mid ?: '',
        $material ? $material->label() : '',
        (string) $item->get('field_supplier_item_number')->value,
        (string) $item->get('field_quantity')->value,
        (string) $item->get('field_material_cost')->value,
      ];
    }
    return $this->csv($rows, 'material-list-' . $wo_material_list->id() . '.csv');
  }

  /**
   * A blank import template with the expected header.
   */
  public function template(): Response {
    return $this->csv([
      ['identifier', 'quantity', 'unit_cost', 'supplier'],
      ['# material ID or supplier item number', 'e.g. 4', 'optional', 'optional'],
    ], 'material-import-template.csv');
  }

  private function csv(array $rows, string $filename): Response {
    $h = fopen('php://temp', 'r+');
    foreach ($rows as $r) {
      fputcsv($h, $r);
    }
    rewind($h);
    $out = stream_get_contents($h);
    fclose($h);
    return new Response($out, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control' => 'no-store',
    ]);
  }

}
