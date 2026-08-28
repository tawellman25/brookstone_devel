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
    $map = ['identifier' => 0, 'quantity' => 1, 'unit_cost' => 2, 'supplier' => 3, 'description' => NULL];
    $header = array_map(fn($c) => strtolower(trim((string) $c)), $table[0]);
    $looksHeader = FALSE;
    foreach ($header as $i => $h) {
      if (preg_match('/\b(desc|description)\b/', $h)) { $map['description'] = $i; $looksHeader = TRUE; }
      elseif (preg_match('/\b(id|sku|item|material|part|identifier)\b/', $h)) { $map['identifier'] = $i; $looksHeader = TRUE; }
      elseif (preg_match('/\b(qty|quantity|count)\b/', $h)) { $map['quantity'] = $i; $looksHeader = TRUE; }
      // "Your Price" wins over "Retail Price" because it is later in the row.
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
        'description' => $map['description'] !== NULL ? trim((string) ($cells[$map['description']] ?? '')) : '',
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
  public function matchRow(string $identifier, string $description = ''): array {
    $id = trim($identifier);
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

    // 3. Fuzzy — by description first (product name), then the identifier.
    foreach ([$description, $id] as $needle) {
      $cands = $this->fuzzyCandidates((string) $needle);
      if ($cands) {
        return [
          'status' => 'ambiguous', 'candidates' => $cands,
          'material_id' => (int) array_key_first($cands), 'material_label' => reset($cands),
        ];
      }
    }

    return ['status' => 'unmatched'];
  }

  /**
   * BC shim — match by identifier only.
   */
  public function matchIdentifier(string $identifier): array {
    return $this->matchRow($identifier, '');
  }

  /**
   * Parse a nominal size to a decimal so "1-1/2 in." (1.5) is distinct from
   * "1/2 in." (0.5). Handles mixed numbers, fractions, and whole inches.
   */
  private function extractSize(string $text): ?float {
    // Mixed number: 1-1/2, 2 - 3/4.
    if (preg_match('/(\d+)\s*-\s*(\d+)\s*\/\s*(\d+)/', $text, $m)) {
      return (float) $m[1] + (float) $m[2] / max(1, (float) $m[3]);
    }
    // Fraction: 1/2, 3/4.
    if (preg_match('/(?<!\d)(\d+)\s*\/\s*(\d+)/', $text, $m)) {
      return (float) $m[1] / max(1, (float) $m[2]);
    }
    // Whole inches: 1 in., 2", 3 inch.
    if (preg_match('/(?<!\/)(?<!\d)(\d+)\s*(?:in\b|inch|")/i', $text, $m)) {
      return (float) $m[1];
    }
    return NULL;
  }

  /**
   * Candidate materials whose title overlaps the given text (tokens ≥ 3 chars),
   * ranked by token overlap and — when a nominal size is present — filtered to
   * matching-size items so 1-1/2 in. never resolves to 1/2 in. Up to 8.
   */
  private function fuzzyCandidates(string $text): array {
    $text = trim($text);
    if ($text === '') {
      return [];
    }
    // Word tokens: ≥ 3 chars, letters (drop pure numbers — size is handled below).
    $tokens = array_values(array_unique(array_filter(
      preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [],
      fn($w) => strlen($w) >= 3 && !ctype_digit($w)
    )));
    $desc_size = $this->extractSize($text);
    // The canonical size string as BOS stores it, e.g. "1-1/2 in." — used to
    // narrow candidates to the right size at the DB level.
    $size_str = NULL;
    if (preg_match('/(\d+-\d+\/\d+|\d+\/\d+|\d+)\s*(?:in\b\.?|inch|")/i', $text, $mm)) {
      $size_str = preg_replace('/\s+/', '', $mm[1]) . ' in.';
    }
    $s = $this->etm->getStorage('material');

    $build = function () use ($s, $tokens, $text) {
      $q = $s->getQuery()->accessCheck(FALSE);
      if ($tokens) {
        $or = $q->orConditionGroup();
        foreach (array_slice($tokens, 0, 6) as $t) {
          $or->condition('title', '%' . $t . '%', 'LIKE');
        }
        $q->condition($or);
      }
      else {
        $q->condition('title', '%' . $text . '%', 'LIKE');
      }
      return $q;
    };

    $q = $build();
    if ($size_str !== NULL) {
      $q->condition('title', '%' . $size_str . '%', 'LIKE');
    }
    $ids = $q->range(0, 80)->execute();
    // If the size string was too strict (formatting), retry without it — the
    // decimal size filter below still keeps only same-size candidates.
    if (!$ids && $size_str !== NULL) {
      $ids = $build()->range(0, 80)->execute();
    }
    if (!$ids) {
      return [];
    }
    $scored = [];
    foreach ($s->loadMultiple($ids) as $m) {
      $title = strtolower((string) $m->label());
      $score = 0;
      foreach ($tokens as $t) {
        if (str_contains($title, $t)) {
          $score++;
        }
      }
      $scored[(int) $m->id()] = ['label' => $m->label(), 'score' => $score, 'size' => $this->extractSize($title)];
    }
    // If the description names a size, keep only same-size candidates (when any).
    if ($desc_size !== NULL) {
      $same = array_filter($scored, fn($d) => $d['size'] !== NULL && abs($d['size'] - $desc_size) < 0.01);
      if ($same) {
        $scored = $same;
      }
    }
    uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $out = [];
    foreach (array_slice($scored, 0, 8, TRUE) as $mid => $d) {
      $out[$mid] = $d['label'];
    }
    return $out;
  }

  /**
   * Match a whole set of parsed rows (uses the identifier + description).
   */
  public function matchRows(array $rows): array {
    foreach ($rows as &$r) {
      $r += $this->matchRow($r['identifier'], $r['description'] ?? '');
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
  public function import(int $listId, array $rows, ?int $supplierId = NULL, bool $learn = FALSE): array {
    $storage = $this->etm->getStorage('wo_material_list_item');
    $created = $merged = $skipped = 0;
    $links_created = $links_updated = 0;

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
      $sku = trim((string) ($r['supplier_item_number'] ?? ''));

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
      }
      else {
        $values = [
          'type' => 'items',
          'field_list_id' => ['target_id' => $listId],
          'field_parts_used' => ['target_id' => $mid],
          'field_quantity' => $qty,
        ];
        // File cost if provided; else empty → presave fills from material.field_cost_integer.
        if ($cost !== NULL) {
          $values['field_material_cost'] = $cost;
        }
        if ($supplierId) {
          $values['field_purchased_supplier'] = ['target_id' => $supplierId];
        }
        elseif (!empty($r['supplier_id'])) {
          $values['field_purchased_supplier'] = ['target_id' => $r['supplier_id']];
        }
        if ($sku !== '') {
          $values['field_supplier_item_number'] = $sku;
        }
        $storage->create($values)->save();
        $created++;
      }

      // Learn: remember the SKU + update the supplier's unit cost on the
      // material_suppliers link (find-or-create; the module normalizes the SKU
      // and blocks duplicate material+supplier links).
      if ($learn && $supplierId && $sku !== '') {
        $res = $this->upsertSupplierLink($mid, $supplierId, $sku, $cost);
        if ($res === 'created') {
          $links_created++;
        }
        elseif ($res === 'updated') {
          $links_updated++;
        }
      }
    }

    return [
      'created' => $created, 'merged' => $merged, 'skipped' => $skipped,
      'links_created' => $links_created, 'links_updated' => $links_updated,
    ];
  }

  /**
   * Find-or-create the material↔supplier link; refresh its SKU + unit cost.
   */
  private function upsertSupplierLink(int $materialId, int $supplierId, string $sku, ?string $cost): string {
    $storage = $this->etm->getStorage('material_suppliers');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_material', $materialId)
      ->condition('field_supplier', $supplierId)
      ->range(0, 1)->execute();
    if ($ids) {
      $link = $storage->load(reset($ids));
      $changed = FALSE;
      if ($sku !== '' && (string) $link->get('field_supplier_item_number')->value !== $sku) {
        $link->set('field_supplier_item_number', $sku);
        $changed = TRUE;
      }
      if ($cost !== NULL && (string) $link->get('field_supplier_unit_cost')->value !== (string) $cost) {
        $link->set('field_supplier_unit_cost', $cost);
        $changed = TRUE;
      }
      if ($changed) {
        $link->save();
        return 'updated';
      }
      return 'unchanged';
    }
    $values = [
      'type' => 'supplier',
      'field_material' => ['target_id' => $materialId],
      'field_supplier' => ['target_id' => $supplierId],
      'field_supplier_item_number' => $sku,
    ];
    if ($cost !== NULL) {
      $values['field_supplier_unit_cost'] = $cost;
    }
    $storage->create($values)->save();
    return 'created';
  }

}
