<?php

declare(strict_types=1);

namespace Drupal\wo_material_list_management\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;

/**
 * Parse, match, and import material rows into a work-order material list.
 *
 * Matching order: material ID → supplier item number (material_suppliers) →
 * fuzzy title. Cost: use the file's unit cost if given, else leave empty so the
 * existing wo_material_list_management presave fills it from material.field_cost_integer.
 * Duplicates on the same list merge quantities into the existing line.
 */
final class MaterialListImportService {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Parse an uploaded CSV/XLS/XLSX file into raw table rows.
   */
  public function parseFile(FileInterface $file): array {
    $real = $this->fileSystem->realpath($file->getFileUri());
    $ext = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
    $table = [];
    if ($ext === 'csv' || $ext === 'txt') {
      if (($h = fopen($real, 'r')) !== FALSE) {
        while (($cells = fgetcsv($h)) !== FALSE) {
          $table[] = $cells;
        }
        fclose($h);
      }
    }
    else {
      $sheet = SpreadsheetIOFactory::createReaderForFile($real)->load($real)->getActiveSheet();
      $table = $sheet->toArray(NULL, TRUE, FALSE, FALSE);
    }
    return $this->normalizeTable($table);
  }

  /**
   * Parse pasted text (one row per line; comma/tab/multi-space separated).
   */
  public function parsePaste(string $text): array {
    $table = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
      if (trim($line) === '') {
        continue;
      }
      $cells = strpos($line, "\t") !== FALSE
        ? explode("\t", $line)
        : (strpos($line, ',') !== FALSE ? str_getcsv($line) : preg_split('/\s{2,}/', trim($line)));
      $table[] = $cells;
    }
    return $this->normalizeTable($table);
  }

  /**
   * Turn a raw table into assoc rows: identifier, quantity, unit_cost, supplier.
   * Detects a header row; otherwise assumes columns [identifier, qty, cost, supplier].
   */
  private function normalizeTable(array $table): array {
    if (!$table) {
      return [];
    }
    // Strip a UTF-8 BOM from the first cell.
    $first = &$table[0][0];
    if (is_string($first)) {
      $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    }
    unset($first);

    // Header detection.
    $map = ['identifier' => 0, 'quantity' => 1, 'unit_cost' => 2, 'supplier' => 3];
    $header = array_map(fn($c) => strtolower(trim((string) $c)), $table[0]);
    $looksHeader = FALSE;
    foreach ($header as $i => $h) {
      if (preg_match('/\b(id|sku|item|material|part|identifier)\b/', $h)) { $map['identifier'] = $i; $looksHeader = TRUE; }
      elseif (preg_match('/\b(qty|quantity|count)\b/', $h)) { $map['quantity'] = $i; $looksHeader = TRUE; }
      elseif (preg_match('/\b(cost|price|each|unit)\b/', $h)) { $map['unit_cost'] = $i; $looksHeader = TRUE; }
      elseif (preg_match('/\b(supplier|vendor)\b/', $h)) { $map['supplier'] = $i; $looksHeader = TRUE; }
    }
    $rows = [];
    foreach ($table as $n => $cells) {
      if ($n === 0 && $looksHeader) {
        continue;
      }
      $identifier = trim((string) ($cells[$map['identifier']] ?? ''));
      if ($identifier === '') {
        continue;
      }
      $rows[] = [
        'identifier' => $identifier,
        'quantity' => trim((string) ($cells[$map['quantity']] ?? '')),
        'unit_cost' => preg_replace('/[^0-9.]/', '', (string) ($cells[$map['unit_cost']] ?? '')),
        'supplier' => trim((string) ($cells[$map['supplier']] ?? '')),
      ];
    }
    return $rows;
  }

  /**
   * Resolve one identifier to a material.
   *
   * @return array
   *   status (matched|ambiguous|unmatched), material_id, material_label,
   *   candidates[id=>label], supplier_id, supplier_item_number.
   */
  public function matchIdentifier(string $identifier): array {
    $id = trim($identifier);
    if ($id === '') {
      return ['status' => 'unmatched'];
    }
    $matStorage = $this->etm->getStorage('material');

    // 1. Material entity ID.
    if (ctype_digit($id) && ($m = $matStorage->load($id))) {
      return ['status' => 'matched', 'material_id' => (int) $id, 'material_label' => $m->label()];
    }

    // 2. Supplier item number → material_suppliers → material.
    $linkStorage = $this->etm->getStorage('material_suppliers');
    $linkIds = $linkStorage->getQuery()->accessCheck(FALSE)
      ->condition('field_supplier_item_number', $id)
      ->sort('field_preferred_supplier', 'DESC')
      ->range(0, 5)->execute();
    foreach ($linkStorage->loadMultiple($linkIds) as $link) {
      $mid = $link->get('field_material')->target_id ?? NULL;
      if ($mid && ($m = $matStorage->load($mid))) {
        return [
          'status' => 'matched', 'material_id' => (int) $mid, 'material_label' => $m->label(),
          'supplier_id' => $link->get('field_supplier')->target_id ?? NULL,
          'supplier_item_number' => $id,
        ];
      }
    }

    // 3. Fuzzy title contains.
    $q = $matStorage->getQuery()->accessCheck(FALSE)
      ->condition('title', '%' . $id . '%', 'LIKE')->range(0, 10)->execute();
    if ($q) {
      $cands = [];
      foreach ($matStorage->loadMultiple($q) as $m) {
        $cands[(int) $m->id()] = $m->label();
      }
      return [
        'status' => 'ambiguous', 'candidates' => $cands,
        'material_id' => (int) array_key_first($cands), 'material_label' => reset($cands),
      ];
    }

    return ['status' => 'unmatched'];
  }

  /**
   * Match a whole set of parsed rows.
   */
  public function matchRows(array $rows): array {
    foreach ($rows as &$r) {
      $r += $this->matchIdentifier($r['identifier']);
    }
    return $rows;
  }

  /**
   * Import resolved rows into a material list. Merges duplicates by quantity.
   *
   * @param array $rows
   *   Each: material_id, quantity, unit_cost, supplier_id, supplier_item_number,
   *   include (bool).
   *
   * @return array
   *   created, merged, skipped counts.
   */
  public function import(int $listId, array $rows): array {
    $storage = $this->etm->getStorage('wo_material_list_item');
    $created = $merged = $skipped = 0;

    foreach ($rows as $r) {
      $mid = (int) ($r['material_id'] ?? 0);
      if (empty($r['include']) || $mid <= 0) {
        $skipped++;
        continue;
      }
      $qty = (int) ($r['quantity'] ?? 0);
      if ($qty < 1) {
        $qty = 1;
      }
      $cost = (isset($r['unit_cost']) && $r['unit_cost'] !== '' && is_numeric($r['unit_cost'])) ? $r['unit_cost'] : NULL;

      // Merge into an existing line for the same material on this list.
      $existing = $storage->getQuery()->accessCheck(FALSE)
        ->condition('field_list_id', $listId)
        ->condition('field_parts_used', $mid)
        ->range(0, 1)->execute();
      if ($existing) {
        $item = $storage->load(reset($existing));
        $item->set('field_quantity', (int) $item->get('field_quantity')->value + $qty);
        // Keep the existing cost snapshot; only merging quantity.
        $item->save();
        $merged++;
        continue;
      }

      $values = [
        'type' => 'items',
        'field_list_id' => ['target_id' => $listId],
        'field_parts_used' => ['target_id' => $mid],
        'field_quantity' => $qty,
      ];
      // File cost if provided; else leave empty → presave fills from material.field_cost_integer.
      if ($cost !== NULL) {
        $values['field_material_cost'] = $cost;
      }
      if (!empty($r['supplier_id'])) {
        $values['field_purchased_supplier'] = ['target_id' => $r['supplier_id']];
      }
      if (!empty($r['supplier_item_number'])) {
        $values['field_supplier_item_number'] = $r['supplier_item_number'];
      }
      $storage->create($values)->save();
      $created++;
    }

    return ['created' => $created, 'merged' => $merged, 'skipped' => $skipped];
  }

}
