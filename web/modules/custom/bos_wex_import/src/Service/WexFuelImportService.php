<?php

declare(strict_types=1);

namespace Drupal\bos_wex_import\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;

/**
 * WEX fuel transaction import service.
 *
 * Owns parsing, resolution (driver/vehicle), entity creation, and
 * downstream side effects (vehicle mileage update). All public methods
 * are deterministic and side-effect-isolated so the form, batch
 * processor, and any future automation can compose them.
 *
 * Resolution pipeline per row:
 *   parseFile → validateHeaders → importRow ⟶ resolveDriver, resolveVehicle
 *                                            → determineMatchStatus
 *                                            → isDuplicate (skip if yes)
 *                                            → save entity
 *                                            → updateVehicleMileage
 *                                              (if matched + odometer)
 */
final class WexFuelImportService {

  /**
   * Required column headers in the WEX export.
   * Missing any of these aborts the import with a clear error.
   */
  private const REQUIRED_HEADERS = [
    'Transaction ID',
    'Transaction Date',
    'Custom Vehicle/Asset ID',
    'Driver Prompt ID',
    'Units',
    'Net Cost',
  ];

  /**
   * Field mapping: WEX column → entity field name. Used as the canonical
   * source for "what columns are populated where". Datetime combination
   * (Transaction Date + Transaction Time) and computed fields (driver/
   * equipment/match_status) are handled separately in importRow().
   */
  private const STRING_FIELD_MAP = [
    'Card Number' => 'field_card_number_masked',
    'Custom Vehicle/Asset ID' => 'field_vehicle_asset_id_raw',
    'VIN' => 'field_vin_raw',
    'Driver First Name' => 'field_driver_first_name_raw',
    'Driver Last Name' => 'field_driver_last_name_raw',
    'Driver Department' => 'field_driver_department_snapshot',
    'Merchant Name' => 'field_merchant_name',
    'Merchant Brand' => 'field_merchant_brand',
    'Merchant City' => 'field_merchant_city',
    'Merchant State / Province' => 'field_merchant_state',
    'Merchant Postal Code' => 'field_merchant_postal_code',
    'Product' => 'field_product_code',
    'Product Class' => 'field_product_class',
    'Product Description' => 'field_product_description',
  ];

  private const DECIMAL_FIELD_MAP = [
    'Units' => 'field_units',
    'Unit Cost' => 'field_unit_cost',
    'Total Fuel Cost' => 'field_total_fuel_cost',
    'Net Cost' => 'field_net_cost',
    'Fuel Economy' => 'field_fuel_economy',
  ];

  private const INTEGER_FIELD_MAP = [
    'Current Odometer' => 'field_current_odometer',
    'Adjusted Odometer' => 'field_adjusted_odometer',
    'Previous Odometer' => 'field_previous_odometer',
    'Distance Driven' => 'field_distance_driven',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $em,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  // ──────────────────────────────────────────────────────────────────────
  // FILE PARSING
  // ──────────────────────────────────────────────────────────────────────

  /**
   * Parse the uploaded WEX export file.
   *
   * @return array<int, array<string, mixed>>
   *   List of associative arrays keyed by column header.
   *
   * @throws \InvalidArgumentException
   */
  public function parseFile(string $filepath): array {
    $real = $this->fileSystem->realpath($filepath) ?: $filepath;
    if (!is_readable($real)) {
      throw new \InvalidArgumentException("WEX import file not readable: $filepath");
    }
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    return match ($ext) {
      'csv' => $this->parseCsv($real),
      'xls', 'xlsx' => $this->parseXlsx($real),
      default => throw new \InvalidArgumentException(
        "Unsupported file format '.$ext'. Use .csv or .xlsx."
      ),
    };
  }

  /** @return array<int, array<string, mixed>> */
  private function parseCsv(string $real): array {
    $rows = [];
    $headers = NULL;
    $h = fopen($real, 'r');
    if (!$h) {
      throw new \InvalidArgumentException("Cannot open CSV: $real");
    }
    try {
      while (($cells = fgetcsv($h)) !== FALSE) {
        if ($headers === NULL) {
          // Trim BOM + whitespace from first header cell.
          if (isset($cells[0])) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
          }
          $headers = array_map(fn($c) => trim((string) $c), $cells);
          continue;
        }
        // Skip blank lines.
        if (count($cells) === 1 && trim((string) ($cells[0] ?? '')) === '') {
          continue;
        }
        $row = [];
        foreach ($headers as $i => $name) {
          $row[$name] = $cells[$i] ?? '';
        }
        $rows[] = $row;
      }
    }
    finally {
      fclose($h);
    }
    return $rows;
  }

  /** @return array<int, array<string, mixed>> */
  private function parseXlsx(string $real): array {
    $reader = SpreadsheetIOFactory::createReaderForFile($real);
    $reader->setReadDataOnly(TRUE);
    $spreadsheet = $reader->load($real);
    $sheet = $spreadsheet->getActiveSheet();
    $array = $sheet->toArray(NULL, TRUE, TRUE, FALSE);
    if (empty($array)) {
      return [];
    }
    $headers = array_map(fn($c) => trim((string) ($c ?? '')), array_shift($array));
    $rows = [];
    foreach ($array as $cells) {
      if (count(array_filter($cells, fn($c) => trim((string) ($c ?? '')) !== '')) === 0) {
        // Skip fully-blank row.
        continue;
      }
      $row = [];
      foreach ($headers as $i => $name) {
        $row[$name] = $cells[$i] ?? '';
      }
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * @param string[] $headers
   * @return string[]  Missing required headers (empty = all present).
   */
  public function validateHeaders(array $headers): array {
    $present = array_map('strtolower', array_map('trim', $headers));
    $missing = [];
    foreach (self::REQUIRED_HEADERS as $required) {
      if (!in_array(strtolower($required), $present, TRUE)) {
        $missing[] = $required;
      }
    }
    return $missing;
  }

  // ──────────────────────────────────────────────────────────────────────
  // RESOLUTION
  // ──────────────────────────────────────────────────────────────────────

  public function resolveDriver(string $promptIdPadded): ?int {
    if ($promptIdPadded === '' || $promptIdPadded === '0000') {
      return NULL;
    }
    try {
      $ids = $this->em->getStorage('profile')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'teammate_profile')
        ->condition('field_wex_driver_prompt_id', $promptIdPadded)
        ->range(0, 1)
        ->execute();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('bos_wex_import')->error(
        'Driver lookup failed for prompt @p: @msg',
        ['@p' => $promptIdPadded, '@msg' => $e->getMessage()]
      );
      return NULL;
    }
    if (empty($ids)) {
      return NULL;
    }
    $profile = $this->em->getStorage('profile')->load(reset($ids));
    if (!$profile) {
      return NULL;
    }
    $owner = $profile->getOwnerId();
    return $owner > 0 ? (int) $owner : NULL;
  }

  public function resolveVehicle(int $assetId): ?int {
    if ($assetId <= 0) {
      return NULL;
    }
    try {
      $ids = $this->em->getStorage('equipment')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'vehicles')
        ->condition('field_vehicle_number', $assetId)
        ->range(0, 1)
        ->execute();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('bos_wex_import')->error(
        'Vehicle lookup failed for asset @a: @msg',
        ['@a' => $assetId, '@msg' => $e->getMessage()]
      );
      return NULL;
    }
    if (empty($ids)) {
      return NULL;
    }
    return (int) reset($ids);
  }

  public function determineMatchStatus(?int $driverUid, ?int $equipmentId): string {
    if ($driverUid === NULL && $equipmentId === NULL) {
      return 'unmatched_both';
    }
    if ($driverUid === NULL) {
      return 'unmatched_driver';
    }
    if ($equipmentId === NULL) {
      return 'unmatched_vehicle';
    }
    return 'matched';
  }

  public function isDuplicate(string $wexTransactionId): bool {
    if ($wexTransactionId === '') {
      return FALSE;
    }
    $count = (int) $this->em->getStorage('equipment_fuel_transaction')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_wex_transaction_id', $wexTransactionId)
      ->count()
      ->execute();
    return $count > 0;
  }

  // ──────────────────────────────────────────────────────────────────────
  // ROW IMPORT
  // ──────────────────────────────────────────────────────────────────────

  /**
   * Process a single parsed row.
   *
   * @return array{status:string, transaction_id:string, match_status:?string, message:?string, entity_id:?int}
   */
  public function importRow(array $row): array {
    $txId = trim((string) ($row['Transaction ID'] ?? ''));
    if ($txId === '') {
      return [
        'status' => 'error',
        'transaction_id' => '',
        'match_status' => NULL,
        'message' => 'Missing Transaction ID',
        'entity_id' => NULL,
      ];
    }

    if ($this->isDuplicate($txId)) {
      return [
        'status' => 'skipped_duplicate',
        'transaction_id' => $txId,
        'match_status' => NULL,
        'message' => NULL,
        'entity_id' => NULL,
      ];
    }

    // Driver Prompt ID — zero-pad to 4 chars.
    $promptRaw = trim((string) ($row['Driver Prompt ID'] ?? ''));
    $promptPadded = $promptRaw === '' ? '' : str_pad($promptRaw, 4, '0', STR_PAD_LEFT);
    $driverUid = $promptPadded === '' ? NULL : $this->resolveDriver($promptPadded);

    // Vehicle Asset ID.
    $assetRaw = trim((string) ($row['Custom Vehicle/Asset ID'] ?? ''));
    $assetInt = $assetRaw === '' ? 0 : (int) $assetRaw;
    $equipmentId = $this->resolveVehicle($assetInt);

    $matchStatus = $this->determineMatchStatus($driverUid, $equipmentId);

    // Build the entity field values.
    $values = [
      'type' => 'standard',
      'field_wex_transaction_id' => $txId,
      'field_match_status' => $matchStatus,
      'field_transaction_date' => $this->parseTransactionDateTime($row),
    ];

    $postedDate = $this->parseDate($row['Posted Date'] ?? NULL, dateOnly: TRUE);
    if ($postedDate !== NULL) {
      $values['field_posted_date'] = $postedDate;
    }

    foreach (self::STRING_FIELD_MAP as $col => $field) {
      $val = $this->trimOrNull($row[$col] ?? NULL);
      if ($val !== NULL) {
        $values[$field] = $val;
      }
    }
    foreach (self::DECIMAL_FIELD_MAP as $col => $field) {
      $val = $this->parseDecimal($row[$col] ?? NULL);
      if ($val !== NULL) {
        $values[$field] = $val;
      }
    }
    foreach (self::INTEGER_FIELD_MAP as $col => $field) {
      $val = $this->parseInt($row[$col] ?? NULL);
      if ($val !== NULL) {
        $values[$field] = $val;
      }
    }

    if ($promptPadded !== '') {
      $values['field_driver_prompt_id_raw'] = $promptPadded;
    }
    if ($driverUid !== NULL) {
      $values['field_driver'] = $driverUid;
    }
    if ($equipmentId !== NULL) {
      $values['field_equipment'] = $equipmentId;
    }

    try {
      $entity = $this->em->getStorage('equipment_fuel_transaction')->create($values);
      $entity->save();
    }
    catch (EntityStorageException $e) {
      $this->loggerFactory->get('bos_wex_import')->error(
        'Failed to save WEX transaction @tx: @msg',
        ['@tx' => $txId, '@msg' => $e->getMessage()]
      );
      return [
        'status' => 'error',
        'transaction_id' => $txId,
        'match_status' => NULL,
        'message' => $e->getMessage(),
        'entity_id' => NULL,
      ];
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('bos_wex_import')->error(
        'Unexpected error saving WEX transaction @tx: @msg',
        ['@tx' => $txId, '@msg' => $e->getMessage()]
      );
      return [
        'status' => 'error',
        'transaction_id' => $txId,
        'match_status' => NULL,
        'message' => $e->getMessage(),
        'entity_id' => NULL,
      ];
    }

    // Mileage auto-update — only when vehicle resolved AND we have an
    // odometer value. Prefer Adjusted Odometer when populated.
    if ($matchStatus === 'matched' || $equipmentId !== NULL) {
      $odo = $this->parseInt($row['Adjusted Odometer'] ?? NULL)
        ?? $this->parseInt($row['Current Odometer'] ?? NULL);
      if ($odo !== NULL && $odo > 0 && $equipmentId !== NULL) {
        $this->updateVehicleMileage(
          $equipmentId,
          $odo,
          (string) $values['field_transaction_date'],
          $txId
        );
      }
    }

    return [
      'status' => 'imported',
      'transaction_id' => $txId,
      'match_status' => $matchStatus,
      'message' => NULL,
      'entity_id' => (int) $entity->id(),
    ];
  }

  // ──────────────────────────────────────────────────────────────────────
  // VEHICLE MILEAGE UPDATE
  // ──────────────────────────────────────────────────────────────────────

  public function updateVehicleMileage(int $equipmentId, int $odometer, string $transactionDateUtc, string $txId): void {
    $vehicle = $this->em->getStorage('equipment')->load($equipmentId);
    if (!$vehicle) {
      $this->loggerFactory->get('bos_wex_import')->warning(
        'Mileage update skipped — equipment @id not found (tx @tx).',
        ['@id' => $equipmentId, '@tx' => $txId]
      );
      return;
    }
    if (!$vehicle->hasField('field_current_mileage')) {
      // Defensive — non-vehicle bundle without the field.
      return;
    }

    $current = $vehicle->get('field_current_mileage')->isEmpty()
      ? NULL
      : (int) $vehicle->get('field_current_mileage')->value;

    if ($current !== NULL && $odometer < $current) {
      $this->loggerFactory->get('bos_wex_import')->warning(
        'Vehicle @id: skipped lower odometer read (@new < current @cur) from WEX transaction @tx. Possible bad pump entry.',
        ['@id' => $equipmentId, '@new' => $odometer, '@cur' => $current, '@tx' => $txId]
      );
      return;
    }

    $vehicle->set('field_current_mileage', $odometer);
    if ($vehicle->hasField('field_current_mileage_updated_on')) {
      $vehicle->set('field_current_mileage_updated_on', $transactionDateUtc);
    }
    try {
      $vehicle->save();
      $this->loggerFactory->get('bos_wex_import')->info(
        'Vehicle @id: mileage @old → @new from WEX transaction @tx.',
        ['@id' => $equipmentId, '@old' => $current ?? '(empty)', '@new' => $odometer, '@tx' => $txId]
      );
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('bos_wex_import')->error(
        'Vehicle @id mileage save failed: @msg',
        ['@id' => $equipmentId, '@msg' => $e->getMessage()]
      );
    }
  }

  // ──────────────────────────────────────────────────────────────────────
  // PARSING HELPERS
  // ──────────────────────────────────────────────────────────────────────

  /**
   * Combine 'Transaction Date' + 'Transaction Time' into UTC ISO 8601.
   * Returns NULL if neither column has a parseable value.
   */
  private function parseTransactionDateTime(array $row): ?string {
    $datePart = trim((string) ($row['Transaction Date'] ?? ''));
    $timePart = trim((string) ($row['Transaction Time'] ?? ''));
    if ($datePart === '') {
      return NULL;
    }
    $combined = $timePart === '' ? $datePart : ($datePart . ' ' . $timePart);
    $dt = $this->parseDateTimeDefensive($combined);
    if (!$dt) {
      return NULL;
    }
    $dt->setTimezone(new \DateTimeZone('UTC'));
    return $dt->format('Y-m-d\TH:i:s');
  }

  /**
   * Defensive datetime parser — tries several likely WEX formats.
   */
  private function parseDateTimeDefensive(string $input): ?\DateTime {
    $tz = new \DateTimeZone(date_default_timezone_get());
    foreach ([
      'm/d/Y H:i:s', 'm/d/Y H:i', 'm/d/Y g:i A', 'm/d/Y g:i:s A',
      'Y-m-d H:i:s', 'Y-m-d H:i',
      'm/d/Y', 'Y-m-d',
    ] as $fmt) {
      $dt = \DateTime::createFromFormat($fmt, $input, $tz);
      $errors = \DateTime::getLastErrors();
      if ($dt && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
        return $dt;
      }
    }
    // Last-ditch: PHP strtotime tolerance.
    try {
      return new \DateTime($input, $tz);
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * Parse a date-only value to UTC 'Y-m-d' (datetime field with date_only).
   */
  private function parseDate(?string $input, bool $dateOnly = TRUE): ?string {
    if ($input === NULL) return NULL;
    $input = trim($input);
    if ($input === '') return NULL;
    $dt = $this->parseDateTimeDefensive($input);
    if (!$dt) return NULL;
    return $dateOnly ? $dt->format('Y-m-d') : $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
  }

  /**
   * Parse a decimal value, tolerating $/, commas, etc.
   * Returns the canonical numeric string (matches Drupal decimal storage).
   */
  private function parseDecimal($input): ?string {
    if ($input === NULL) return NULL;
    $s = trim((string) $input);
    if ($s === '') return NULL;
    $cleaned = preg_replace('/[\$,\s]/', '', $s);
    if ($cleaned === '' || !is_numeric($cleaned)) return NULL;
    return $cleaned;
  }

  private function parseInt($input): ?int {
    if ($input === NULL) return NULL;
    $s = trim((string) $input);
    if ($s === '') return NULL;
    $cleaned = preg_replace('/[,\s]/', '', $s);
    if ($cleaned === '' || !is_numeric($cleaned)) return NULL;
    return (int) $cleaned;
  }

  private function trimOrNull($input): ?string {
    if ($input === NULL) return NULL;
    $s = trim((string) $input);
    return $s === '' ? NULL : $s;
  }

}
