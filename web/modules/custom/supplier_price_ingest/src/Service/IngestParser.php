<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileRepositoryInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reads CSV/XLSX supplier price feeds, applies the supplier's column
 * mapping, and persists one supplier_price_ingest_row entity per
 * source data row.
 *
 * Phase 3.2 — parse only. Matching is added in 3.3.
 *
 * Public API:
 *   - parseUploadedFile(EntityInterface $batch): ParseResult
 *   - countSourceRows(EntityInterface $batch): int
 *
 * Defensive guarantees:
 *   - Never throws out of parseUploadedFile() on a per-row error;
 *     captures and continues.
 *   - On unrecoverable failure of the whole parse (file unreadable,
 *     config missing) the catch block transitions the batch to
 *     status = 'failed' and re-throws so the caller can present the
 *     error.
 *   - field_raw_data on every created row contains the original CSV
 *     cells (associative array by source-header → cell value) so the
 *     audit chain survives.
 */
class IngestParser {

  /**
   * Source-data rows above this count flip the upload flow to Batch API.
   *
   * The form layer decides; the parser itself handles any count.
   */
  public const SYNCHRONOUS_PARSE_THRESHOLD = 500;

  /**
   * Match-tier sentinel for rows that failed to parse cleanly.
   *
   * Mirrors the list_string allowed value on the row entity.
   */
  private const MATCH_TIER_ERROR = 'error';

  /**
   * Initial row status — all rows start in dry_run until matcher (3.3) runs.
   */
  private const ROW_STATUS_DRY_RUN = 'dry_run';

  /**
   * Allowed BOS field machine names that source_columns may target.
   *
   * Validated at config save (presave hook) AND at parse time as a
   * defense-in-depth check.
   */
  public const ALLOWED_ROW_FIELDS = [
    'field_supplier_sku',
    'field_manufacturer_item_number',
    'field_manufacturer_name',
    'field_description',
    'field_unit_cost',
    'field_cost_uom',
    'field_pack_quantity',
  ];

  /**
   * Fields that count as an "identifier" — at least one must be mapped
   * AND present on a row for the row to be considered usable.
   */
  public const IDENTIFIER_FIELDS = [
    'field_supplier_sku',
    'field_manufacturer_item_number',
    'field_description',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Reads the file attached to $batch, parses rows, persists them.
   *
   * Postconditions:
   *   - One supplier_price_ingest_row created per source data row that
   *     made it through identifier/cost gating.
   *   - field_row_count_total updated on the batch.
   *   - Batch status NOT advanced here — matcher (3.3) is responsible
   *     for the dry_run_complete transition.
   *   - Returns a ParseResult summarizing what happened.
   *
   * On unrecoverable failure: batch status → 'failed', exception
   * re-thrown (the form handler catches and presents).
   */
  public function parseUploadedFile(EntityInterface $batch): ParseResult {
    $logger = $this->loggerFactory->get('supplier_price_ingest');
    $created = 0;
    $skipped = 0;
    $errored = 0;
    $parseErrors = [];

    try {
      $config = $this->loadIngestConfig($batch);
      $mapping = $this->parseColumnMapping($config);
      $defaultUom = (string) ($config->get('field_default_cost_uom')->value ?? '');

      $file = $this->loadSourceFile($batch);
      $uri = $file->getFileUri();
      $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

      $rowsIter = match ($extension) {
        'csv'  => $this->iterateCsv($uri, $mapping),
        'xls', 'xlsx' => $this->iterateSpreadsheet($uri, $mapping),
        default => throw new \RuntimeException("Unsupported file extension: $extension"),
      };

      foreach ($rowsIter as $rowNumber => $rowAssoc) {
        $outcome = $this->buildAndSaveRow($batch, $rowNumber, $rowAssoc, $mapping, $defaultUom);
        switch ($outcome['status']) {
          case 'created':
            $created++;
            break;

          case 'skipped':
            $skipped++;
            $parseErrors[] = ['row_number' => $rowNumber, 'message' => $outcome['message']];
            break;

          case 'errored':
            $errored++;
            $parseErrors[] = ['row_number' => $rowNumber, 'message' => $outcome['message']];
            break;
        }
      }

      // Update aggregate count. Other tier counts stay 0 — matcher fills them.
      $batch->set('field_row_count_total', $created + $errored);
      // Skipped rows aren't persisted as entities, so they don't count
      // toward field_row_count_total (which counts row entities).
      $batch->set('field_row_count_skipped', $skipped);
      $batch->save();

      $result = new ParseResult($created, $skipped, $errored, $parseErrors);
      $logger->info('Batch @bid parsed: @summary', [
        '@bid' => $batch->id(),
        '@summary' => $result->summary(),
      ]);
      return $result;
    }
    catch (\Throwable $e) {
      // Catastrophic failure — flip status to 'failed' and re-throw.
      try {
        $batch->set('field_status', 'failed');
        // Stash error details into the dry_run_report so the
        // placeholder batch view can show what blew up.
        $batch->set('field_dry_run_report', json_encode([
          'fatal_error' => $e->getMessage(),
          'created_so_far' => $created,
          'skipped_so_far' => $skipped,
          'errored_so_far' => $errored,
          'parse_errors' => $parseErrors,
        ], JSON_PRETTY_PRINT));
        $batch->save();
      }
      catch (\Throwable $inner) {
        // If we can't even save the failure state, log and swallow —
        // re-throwing the outer is more useful to the caller.
        $logger->critical('Batch @bid: failed to record failure status: @msg', [
          '@bid' => $batch->id(),
          '@msg' => $inner->getMessage(),
        ]);
      }
      $logger->error('Batch @bid parse failed: @msg', [
        '@bid' => $batch->id(),
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Cheap pre-scan to count data rows in the source file (excluding
   * the header row).
   *
   * Used by the upload form to decide synchronous vs Batch API.
   */
  public function countSourceRows(EntityInterface $batch): int {
    $config = $this->loadIngestConfig($batch);
    $mapping = $this->parseColumnMapping($config);
    $file = $this->loadSourceFile($batch);
    $uri = $file->getFileUri();
    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

    if ($extension === 'csv') {
      // Streaming line count, fast even for very large files.
      $handle = fopen($uri, 'r');
      if (!$handle) {
        return 0;
      }
      $lines = 0;
      while (!feof($handle)) {
        if (fgets($handle) !== FALSE) {
          $lines++;
        }
      }
      fclose($handle);
      // Subtract header row(s).
      return max(0, $lines - (int) $mapping['header_row']);
    }

    // For XLSX, load and count rows on the active sheet. Slower but
    // accurate. Acceptable since this only runs once per upload.
    $realpath = \Drupal::service('file_system')->realpath($uri) ?: $uri;
    $spreadsheet = IOFactory::load($realpath);
    $sheet = $spreadsheet->getActiveSheet();
    return max(0, $sheet->getHighestDataRow() - (int) $mapping['header_row']);
  }

  // ── Internals ────────────────────────────────────────────────────

  /**
   * Loads the supplier_ingest_config for the batch's supplier.
   *
   * @throws \RuntimeException If no config exists for this supplier.
   */
  private function loadIngestConfig(EntityInterface $batch): EntityInterface {
    $supplierId = (int) ($batch->get('field_supplier')->target_id ?? 0);
    if ($supplierId <= 0) {
      throw new \RuntimeException('Batch has no supplier reference.');
    }
    $configs = $this->entityTypeManager
      ->getStorage('supplier_ingest_config')
      ->loadByProperties(['field_supplier' => $supplierId]);
    if (!$configs) {
      throw new \RuntimeException(sprintf(
        'No supplier_ingest_config exists for supplier id=%d. Create one at /admin/materials/supplier-ingest/configs/add first.',
        $supplierId,
      ));
    }
    return reset($configs);
  }

  /**
   * Returns the normalized column-mapping array with safe defaults.
   *
   * Shape returned:
   *   [
   *     'source_columns' => [header_string => bos_field_name, ...],
   *     'header_row' => int,
   *     'skip_rows_until_header' => bool,
   *     'case_sensitive_headers' => bool,
   *     'trim_whitespace' => bool,
   *   ]
   *
   * Defense-in-depth: also validates source_columns target fields
   * against the whitelist; presave should have already done this but
   * a config edited via direct DB write would bypass that.
   */
  private function parseColumnMapping(EntityInterface $config): array {
    $raw = (string) ($config->get('field_column_mapping')->value ?? '');
    if ($raw === '') {
      throw new \RuntimeException('supplier_ingest_config has empty field_column_mapping.');
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || !isset($decoded['source_columns']) || !is_array($decoded['source_columns'])) {
      throw new \RuntimeException('field_column_mapping JSON is missing source_columns object.');
    }
    foreach ($decoded['source_columns'] as $header => $target) {
      if (!in_array($target, self::ALLOWED_ROW_FIELDS, TRUE)) {
        throw new \RuntimeException(sprintf(
          'field_column_mapping targets unknown field "%s" (header: "%s"). Allowed: %s',
          $target,
          $header,
          implode(', ', self::ALLOWED_ROW_FIELDS),
        ));
      }
    }
    return [
      'source_columns' => $decoded['source_columns'],
      'header_row' => max(1, (int) ($decoded['header_row'] ?? 1)),
      'skip_rows_until_header' => (bool) ($decoded['skip_rows_until_header'] ?? FALSE),
      'case_sensitive_headers' => (bool) ($decoded['case_sensitive_headers'] ?? FALSE),
      'trim_whitespace' => (bool) ($decoded['trim_whitespace'] ?? TRUE),
    ];
  }

  /**
   * Loads the file entity attached to the batch.
   *
   * @throws \RuntimeException If no file is attached.
   */
  private function loadSourceFile(EntityInterface $batch): EntityInterface {
    $fid = (int) ($batch->get('field_source_file')->target_id ?? 0);
    if ($fid <= 0) {
      throw new \RuntimeException('Batch has no source file attached.');
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file) {
      throw new \RuntimeException("File entity id=$fid not loadable.");
    }
    return $file;
  }

  /**
   * Yields one [row_number => assoc_array] per data row in a CSV.
   *
   * Stream-reads via fgetcsv. Handles UTF-8 BOM on first line.
   * Row number is the 1-indexed position of the data row (after header).
   */
  private function iterateCsv(string $uri, array $mapping): \Generator {
    $handle = fopen($uri, 'r');
    if (!$handle) {
      throw new \RuntimeException("Could not open CSV: $uri");
    }
    try {
      $headers = NULL;
      $headerRow = (int) $mapping['header_row'];
      $skipUntilHeader = (bool) $mapping['skip_rows_until_header'];
      $dataRowNumber = 0;
      $physicalRow = 0;
      $headerKeyMap = $this->buildHeaderKeyMap($mapping['source_columns'], $mapping['case_sensitive_headers'], $mapping['trim_whitespace']);

      while (($cells = fgetcsv($handle)) !== FALSE) {
        $physicalRow++;
        // Strip UTF-8 BOM from the first cell of row 1 if present.
        if ($physicalRow === 1 && isset($cells[0])) {
          $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
        }

        if ($headers === NULL) {
          // Looking for header row.
          if ($skipUntilHeader) {
            // Check whether this row looks like our header (contains all
            // mapped header strings).
            if ($this->rowMatchesHeaders($cells, $mapping)) {
              $headers = $cells;
            }
            continue;
          }
          // header_row matching: row N is header.
          if ($physicalRow === $headerRow) {
            $headers = $cells;
          }
          continue;
        }

        // Data row.
        $dataRowNumber++;
        yield $dataRowNumber => $this->cellsToAssoc($cells, $headers, $headerKeyMap, $mapping);
      }
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Yields one [row_number => assoc_array] per data row in an XLSX/XLS.
   *
   * Loads the file via PhpSpreadsheet IOFactory. Active sheet only.
   *
   * PhpSpreadsheet doesn't accept Drupal stream wrappers (public://...);
   * resolve to a real filesystem path first via file_system->realpath().
   */
  private function iterateSpreadsheet(string $uri, array $mapping): \Generator {
    $realpath = \Drupal::service('file_system')->realpath($uri) ?: $uri;
    $spreadsheet = IOFactory::load($realpath);
    $sheet = $spreadsheet->getActiveSheet();
    $highest = $sheet->getHighestDataRow();
    $headerRow = (int) $mapping['header_row'];
    $skipUntilHeader = (bool) $mapping['skip_rows_until_header'];
    $headerKeyMap = $this->buildHeaderKeyMap($mapping['source_columns'], $mapping['case_sensitive_headers'], $mapping['trim_whitespace']);

    $headers = NULL;
    $dataRowNumber = 0;
    for ($physicalRow = 1; $physicalRow <= $highest; $physicalRow++) {
      $cells = [];
      $highestColumn = $sheet->getHighestDataColumn($physicalRow);
      // Iterate cells across the row.
      $colIdx = 0;
      foreach ($sheet->rangeToArray("A$physicalRow:$highestColumn$physicalRow", NULL, TRUE, FALSE)[0] as $val) {
        $cells[$colIdx++] = is_string($val) ? $val : (string) ($val ?? '');
      }

      if ($headers === NULL) {
        if ($skipUntilHeader) {
          if ($this->rowMatchesHeaders($cells, $mapping)) {
            $headers = $cells;
          }
          continue;
        }
        if ($physicalRow === $headerRow) {
          $headers = $cells;
        }
        continue;
      }

      $dataRowNumber++;
      yield $dataRowNumber => $this->cellsToAssoc($cells, $headers, $headerKeyMap, $mapping);
    }
  }

  /**
   * Build map from normalized header → BOS field name.
   */
  private function buildHeaderKeyMap(array $sourceColumns, bool $caseSensitive, bool $trim): array {
    $out = [];
    foreach ($sourceColumns as $header => $field) {
      $key = $this->normalizeHeader((string) $header, $caseSensitive, $trim);
      $out[$key] = $field;
    }
    return $out;
  }

  private function normalizeHeader(string $header, bool $caseSensitive, bool $trim): string {
    if ($trim) {
      $header = trim($header);
    }
    if (!$caseSensitive) {
      $header = strtolower($header);
    }
    return $header;
  }

  /**
   * Check whether a candidate row matches the configured header set
   * (used for skip_rows_until_header detection).
   */
  private function rowMatchesHeaders(array $cells, array $mapping): bool {
    $normalized = [];
    foreach ($cells as $c) {
      $normalized[] = $this->normalizeHeader((string) $c, $mapping['case_sensitive_headers'], $mapping['trim_whitespace']);
    }
    foreach (array_keys($mapping['source_columns']) as $expected) {
      $key = $this->normalizeHeader((string) $expected, $mapping['case_sensitive_headers'], $mapping['trim_whitespace']);
      if (!in_array($key, $normalized, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Map [colIndex => cellValue] + headers → [bosField => cellValue, '_raw' => header→cell].
   *
   * '_raw' is the original header-keyed associative array for
   * field_raw_data. Always preserves the source view; never strips
   * unmapped columns.
   */
  private function cellsToAssoc(array $cells, array $headers, array $headerKeyMap, array $mapping): array {
    $raw = [];
    $mapped = [];
    foreach ($cells as $idx => $val) {
      $rawHeader = $headers[$idx] ?? "col_$idx";
      $raw[$rawHeader] = is_string($val) ? $val : (string) ($val ?? '');
      $key = $this->normalizeHeader((string) $rawHeader, $mapping['case_sensitive_headers'], $mapping['trim_whitespace']);
      if (isset($headerKeyMap[$key])) {
        $bosField = $headerKeyMap[$key];
        $mapped[$bosField] = $raw[$rawHeader];
      }
    }
    $mapped['_raw'] = $raw;
    return $mapped;
  }

  /**
   * Build and save a supplier_price_ingest_row from a parsed row.
   *
   * @return array{status: string, message: string}
   *   status ∈ {'created','skipped','errored'}.
   *   message is human-readable; '' for created.
   */
  private function buildAndSaveRow(EntityInterface $batch, int $rowNumber, array $mapped, array $mapping, string $defaultUom): array {
    try {
      $raw = $mapped['_raw'] ?? [];
      unset($mapped['_raw']);

      // Gating: identifier + unit cost required.
      $hasIdentifier = FALSE;
      foreach (self::IDENTIFIER_FIELDS as $f) {
        if (isset($mapped[$f]) && trim((string) $mapped[$f]) !== '') {
          $hasIdentifier = TRUE;
          break;
        }
      }
      $hasUnitCost = isset($mapped['field_unit_cost']) && trim((string) $mapped['field_unit_cost']) !== '';
      if (!$hasIdentifier || !$hasUnitCost) {
        return [
          'status' => 'skipped',
          'message' => sprintf(
            'row skipped: %s',
            !$hasIdentifier && !$hasUnitCost
              ? 'no identifier AND no unit cost'
              : (!$hasIdentifier ? 'no identifier (SKU / mfr # / description)' : 'no unit cost'),
          ),
        ];
      }

      $errorMessages = [];

      // Cost normalization.
      $costRaw = (string) ($mapped['field_unit_cost'] ?? '');
      $normalizedCost = $this->normalizeCost($costRaw);
      if ($normalizedCost === NULL) {
        $errorMessages[] = "unit cost '$costRaw' is not a valid decimal";
        $unitCostValue = NULL;
      }
      else {
        $unitCostValue = $normalizedCost;
      }

      // UOM normalization with default fallback.
      $uomValue = NULL;
      if (isset($mapped['field_cost_uom'])) {
        $candidate = strtolower(trim((string) $mapped['field_cost_uom']));
        if ($this->isAllowedUom($candidate)) {
          $uomValue = $candidate;
        }
        elseif ($defaultUom !== '' && $this->isAllowedUom($defaultUom)) {
          $uomValue = $defaultUom;
        }
        elseif ($candidate !== '') {
          $errorMessages[] = "cost UOM '$candidate' is not in allowed values and no default_cost_uom is set";
        }
      }
      elseif ($defaultUom !== '' && $this->isAllowedUom($defaultUom)) {
        $uomValue = $defaultUom;
      }

      // Pack quantity coercion.
      $packQty = NULL;
      if (isset($mapped['field_pack_quantity']) && trim((string) $mapped['field_pack_quantity']) !== '') {
        $packRaw = preg_replace('/[^\d]/', '', (string) $mapped['field_pack_quantity']);
        if ($packRaw !== '' && $packRaw !== NULL) {
          $packQty = (int) $packRaw;
        }
      }

      // Encode raw data. If encoding fails, that's an errored row.
      $rawJson = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      if ($rawJson === FALSE) {
        $errorMessages[] = 'raw row JSON encode failed: ' . json_last_error_msg();
        $rawJson = json_encode(['_encode_error' => json_last_error_msg()]);
      }

      $values = [
        'type' => 'row',
        'title' => sprintf('Row %d / Batch %d', $rowNumber, $batch->id()),
        'uid' => $this->currentUser->id(),
        'field_batch' => $batch->id(),
        'field_row_number' => $rowNumber,
        'field_raw_data' => $rawJson,
        'field_row_status' => self::ROW_STATUS_DRY_RUN,
      ];
      foreach (['field_supplier_sku', 'field_manufacturer_item_number', 'field_manufacturer_name', 'field_description'] as $f) {
        if (isset($mapped[$f]) && trim((string) $mapped[$f]) !== '') {
          $values[$f] = trim((string) $mapped[$f]);
        }
      }
      if ($unitCostValue !== NULL) {
        $values['field_unit_cost'] = $unitCostValue;
      }
      if ($uomValue !== NULL) {
        $values['field_cost_uom'] = $uomValue;
      }
      if ($packQty !== NULL) {
        $values['field_pack_quantity'] = $packQty;
      }
      if (!empty($errorMessages)) {
        $values['field_match_tier'] = self::MATCH_TIER_ERROR;
        $values['field_resolution_notes'] = implode("\n", $errorMessages);
      }

      $row = $this->entityTypeManager->getStorage('supplier_price_ingest_row')->create($values);
      $row->save();

      return !empty($errorMessages)
        ? ['status' => 'errored', 'message' => implode('; ', $errorMessages)]
        : ['status' => 'created', 'message' => ''];
    }
    catch (\Throwable $e) {
      // Even creation/save can throw — capture it and continue.
      $this->loggerFactory->get('supplier_price_ingest')->error(
        'Row @n on batch @bid: save failed: @msg',
        ['@n' => $rowNumber, '@bid' => $batch->id(), '@msg' => $e->getMessage()],
      );
      return ['status' => 'errored', 'message' => 'row save failed: ' . $e->getMessage()];
    }
  }

  /**
   * Normalize a raw cost string ("$1,234.56", "1234.56", " 9.99 ") to
   * a decimal string suitable for the decimal field. Returns NULL on
   * unparseable input.
   */
  private function normalizeCost(string $raw): ?string {
    $stripped = preg_replace('/[\\s\\$,]/', '', $raw);
    if ($stripped === NULL || $stripped === '') {
      return NULL;
    }
    if (!is_numeric($stripped)) {
      return NULL;
    }
    // Two-decimal places, consistent with field schema.
    return number_format((float) $stripped, 2, '.', '');
  }

  /**
   * Check a UOM value against the storage's allowed_values list.
   */
  private function isAllowedUom(string $value): bool {
    static $allowed = NULL;
    if ($allowed === NULL) {
      $storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('supplier_price_ingest_row', 'field_cost_uom');
      $allowed = $storage ? array_keys($storage->getSetting('allowed_values') ?? []) : [];
    }
    return in_array($value, $allowed, TRUE);
  }

}
