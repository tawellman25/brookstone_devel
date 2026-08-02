<?php

declare(strict_types=1);

namespace Drupal\bos_it_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;

/**
 * Imports IT assets from the Office Network Baseline workbook into
 * equipment:it_equipment. Idempotent on Asset ID (field_equipment_number).
 *
 * Patterned after WexFuelImportService: all parse/map/upsert lives here; the
 * Drush command is a thin CLI wrapper. Reads two tabs — "Workstations" (rich PC
 * rows) and "Network Devices" (gateway/NAS/switches/printers). Network-device
 * rows whose Asset ID starts BUS-PC- are skipped (already imported, richer,
 * from Workstations).
 */
final class ItEquipmentImportService {

  private const BUNDLE = 'it_equipment';
  private const ENTITY = 'equipment';
  private const DEFAULT_STATUS_TID = 1301; // Active.

  /** Known brands for splitting "Netgear GS605v5" → make + model. */
  private const BRANDS = [
    'HP', 'Hewlett-Packard', 'Brother', 'Canon', 'Epson', 'Lexmark', 'Xerox',
    'Netgear', 'Cisco', 'Ubiquiti', 'TP-Link', 'D-Link', 'Linksys', 'Dell',
    'Synology', 'QNAP', 'Western Digital', 'WD', 'Buffalo', 'Ricoh',
  ];

  /** Cache: device-type term name → tid. */
  private array $typeTermCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Import from a local CSV/XLSX baseline file.
   *
   * @return array{status:string,message?:string,created?:int,updated?:int,skipped?:int,errors?:int,rows?:int}
   */
  public function importFromFile(string $filepath): array {
    $real = realpath($filepath) ?: $filepath;
    if (!is_file($real) || !is_readable($real)) {
      return ['status' => 'unreadable', 'message' => "File not found or unreadable: {$filepath}"];
    }

    try {
      $reader = SpreadsheetIOFactory::createReaderForFile($real);
      $reader->setReadDataOnly(TRUE);
      $spreadsheet = $reader->load($real);
    }
    catch (\Throwable $e) {
      return ['status' => 'parse_error', 'message' => 'Could not read workbook: ' . $e->getMessage()];
    }

    $tally = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => 0];

    // Workstations tab — rich PC rows.
    foreach ($this->readSheet($spreadsheet, 'Workstations') as $row) {
      $tally['rows']++;
      $this->apply($this->mapWorkstation($row), $tally);
    }

    // Network Devices tab — non-PC assets (PCs already done above).
    foreach ($this->readSheet($spreadsheet, 'Network Devices') as $row) {
      $assetId = trim((string) ($row['Asset ID'] ?? ''));
      if ($assetId === '' || str_starts_with(strtoupper($assetId), 'BUS-PC-')) {
        continue;
      }
      $tally['rows']++;
      $this->apply($this->mapNetworkDevice($row), $tally);
    }

    return ['status' => 'imported'] + $tally;
  }

  /**
   * Read a named sheet into assoc rows. The baseline tabs carry a 2-row
   * title/subtitle preamble, so the header row is located by finding the row
   * that contains an "Asset ID" cell rather than assuming row 1.
   *
   * @return array<int,array<string,string>>
   */
  private function readSheet($spreadsheet, string $sheetName): array {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    if (!$sheet) {
      return [];
    }
    $grid = $sheet->toArray(NULL, TRUE, TRUE, FALSE);
    // Find the header row (contains "Asset ID").
    $headerIdx = NULL;
    foreach ($grid as $i => $cells) {
      foreach ($cells as $c) {
        if (strcasecmp(trim((string) ($c ?? '')), 'Asset ID') === 0) {
          $headerIdx = $i;
          break 2;
        }
      }
    }
    if ($headerIdx === NULL) {
      return [];
    }
    $headers = array_map(fn($c) => trim((string) ($c ?? '')), $grid[$headerIdx]);
    $rows = [];
    foreach (array_slice($grid, $headerIdx + 1, NULL, TRUE) as $cells) {
      if (count(array_filter($cells, fn($c) => trim((string) ($c ?? '')) !== '')) === 0) {
        continue;
      }
      $assoc = [];
      foreach ($headers as $col => $name) {
        if ($name === '') {
          continue;
        }
        $assoc[$name] = trim((string) ($cells[$col] ?? ''));
      }
      if (trim((string) ($assoc['Asset ID'] ?? '')) !== '') {
        $rows[] = $assoc;
      }
    }
    return $rows;
  }

  /**
   * Map a Workstations row → field values.
   *
   * @return array<string,mixed>
   */
  private function mapWorkstation(array $r): array {
    return [
      'field_equipment_number' => $r['Asset ID'] ?? '',
      'field_it_hostname' => $r['Computer Name'] ?? '',
      'field_it_user' => $r['Current User'] ?? '',
      'field_equipment_make' => $r['Manufacturer'] ?? '',
      'field_model' => $r['Model'] ?? '',
      'field_serial_code_number' => $r['Serial Number'] ?? '',
      'field_it_os' => $r['Operating System'] ?? '',
      'field_it_os_build' => $r['Build'] ?? '',
      'field_it_cpu' => $r['Processor'] ?? '',
      'field_it_ram_gb' => $this->toDecimal($r['RAM GB'] ?? ''),
      'field_it_ipv4' => $r['IPv4'] ?? '',
      'field_it_gateway' => $r['Gateway'] ?? '',
      'field_it_dns' => $r['DNS Servers'] ?? '',
      'field_it_dhcp' => $this->toBool($r['DHCP'] ?? ''),
      'field_it_network_profile' => $r['Network Profile'] ?? '',
      'field_it_mac' => $r['MAC Address'] ?? '',
      'field_it_link_speed' => $r['Link Speed'] ?? '',
      'field_it_workgroup' => $r['Workgroup'] ?? '',
      'field_it_disk_encryption' => $r['OS Disk Encryption'] ?? '',
      'field_it_firewall' => $r['Active Firewall'] ?? '',
      'field_it_time_sync' => $r['Time Sync'] ?? '',
      'field_it_antivirus' => $r['Antivirus Products'] ?? '',
      'field_it_notes' => $r['Role / Notes'] ?? '',
      'field_equipment_type' => $this->deviceTypeTid($r['Asset ID'] ?? '', 'Windows workstation'),
    ];
  }

  /**
   * Map a Network Devices row → field values.
   *
   * @return array<string,mixed>
   */
  private function mapNetworkDevice(array $r): array {
    $assetId = $r['Asset ID'] ?? '';
    $deviceType = $r['Device Type'] ?? '';
    $name = $r['Hostname / Name'] ?? '';

    // Notes = evidence + next action, kept for context.
    $noteParts = array_filter([
      $r['Evidence'] ?? '' ? 'Evidence: ' . $r['Evidence'] : '',
      $r['Next Action'] ?? '' ? 'Next action: ' . $r['Next Action'] : '',
    ]);

    $values = [
      'field_equipment_number' => $assetId,
      'field_it_ipv4' => $r['IPv4'] ?? '',
      'field_it_mac' => $r['MAC Address'] ?? '',
      'field_it_notes' => implode("\n", $noteParts),
      'field_equipment_type' => $this->deviceTypeTid($assetId, $deviceType),
    ];

    // A "Name" like "Netgear GS605v5 (WS2 area)" carries make + model +
    // location; a bare hostname (e.g. "HP37144F", "BROOKSTONE") is a hostname.
    [$make, $model, $location, $hostname] = $this->splitDeviceName($name, $deviceType);
    if ($make !== '') {
      $values['field_equipment_make'] = $make;
    }
    if ($model !== '') {
      $values['field_model'] = $model;
    }
    if ($location !== '') {
      $values['field_it_location'] = $location;
    }
    if ($hostname !== '') {
      $values['field_it_hostname'] = $hostname;
    }
    return $values;
  }

  /**
   * Split a device "Name" / device-type string into make/model/location/host.
   *
   * @return array{0:string,1:string,2:string,3:string}  [make, model, location, hostname]
   */
  private function splitDeviceName(string $name, string $deviceType): array {
    $name = trim($name);
    $location = '';
    // Location hint in parentheses: "Netgear GS605v5 (WS2 area)".
    if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $name, $m)) {
      $name = trim($m[1]);
      $location = trim($m[2]);
    }

    // If the remaining name starts with a known brand, treat it as make+model
    // (a hardware label), otherwise treat it as a hostname.
    $firstWord = strtok($name, ' ') ?: '';
    $isBrand = FALSE;
    foreach (self::BRANDS as $b) {
      if (strcasecmp($firstWord, $b) === 0) {
        $isBrand = TRUE;
        break;
      }
    }

    if ($isBrand) {
      $make = $firstWord;
      $model = trim(substr($name, strlen($firstWord)));
      return [$make, $model, $location, ''];
    }

    // Not a brand label → it's a hostname. Derive make/model from the device
    // type text if that begins with a brand (e.g. "HP DesignJet T210 24-in",
    // "Brother MFC-J6930DW").
    $make = '';
    $model = '';
    $dtFirst = strtok(trim($deviceType), ' ') ?: '';
    foreach (self::BRANDS as $b) {
      if (strcasecmp($dtFirst, $b) === 0) {
        $make = $dtFirst;
        $model = trim(substr(trim($deviceType), strlen($dtFirst)));
        break;
      }
    }
    return [$make, $model, $location, $name];
  }

  /**
   * Resolve the equipment_types term id for a device, by Asset ID prefix first
   * (most reliable) then by keyword in the device-type text.
   */
  private function deviceTypeTid(string $assetId, string $deviceTypeText): ?int {
    $prefixMap = [
      'BUS-PC-' => 'Desktop PC / Workstation',
      'BUS-NAS-' => 'NAS (Network Attached Storage)',
      'BUS-SW-' => 'Network Switch',
      'BUS-FW-' => 'Router / Gateway',
      'BUS-PRN-' => 'Printer',
      'BUS-NET-UNKNOWN' => 'Unidentified Network Device',
    ];
    $up = strtoupper(trim($assetId));
    foreach ($prefixMap as $prefix => $termName) {
      if (str_starts_with($up, $prefix)) {
        return $this->termIdByName($termName);
      }
    }

    // Keyword fallback on the device-type text.
    $t = strtolower($deviceTypeText);
    $kw = [
      'nas' => 'NAS (Network Attached Storage)',
      'switch' => 'Network Switch',
      'router' => 'Router / Gateway',
      'gateway' => 'Router / Gateway',
      'printer' => 'Printer',
      'workstation' => 'Desktop PC / Workstation',
      'unidentified' => 'Unidentified Network Device',
    ];
    foreach ($kw as $needle => $termName) {
      if (str_contains($t, $needle)) {
        return $this->termIdByName($termName);
      }
    }
    return $this->termIdByName('Unidentified Network Device');
  }

  private function termIdByName(string $name): ?int {
    if (array_key_exists($name, $this->typeTermCache)) {
      return $this->typeTermCache[$name];
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'equipment_types', 'name' => $name]);
    $tid = $terms ? (int) reset($terms)->id() : NULL;
    $this->typeTermCache[$name] = $tid;
    return $tid;
  }

  /**
   * Create or update an it_equipment record, keyed on Asset ID (idempotent).
   */
  private function apply(array $values, array &$tally): void {
    $assetId = trim((string) ($values['field_equipment_number'] ?? ''));
    if ($assetId === '') {
      $tally['skipped']++;
      return;
    }

    try {
      $storage = $this->entityTypeManager->getStorage(self::ENTITY);
      $existing = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', self::BUNDLE)
        ->condition('field_equipment_number', $assetId)
        ->range(0, 1)
        ->execute();

      $isNew = empty($existing);
      $entity = $isNew
        ? $storage->create(['type' => self::BUNDLE])
        : $storage->load(reset($existing));

      foreach ($values as $field => $value) {
        if ($value === '' || $value === NULL) {
          continue;
        }
        if ($entity->hasField($field)) {
          $entity->set($field, $value);
        }
      }

      // Default status on create (Active); never override a later hand-set one.
      if ($isNew && $entity->hasField('field_status') && $entity->get('field_status')->isEmpty()) {
        $entity->set('field_status', ['target_id' => self::DEFAULT_STATUS_TID]);
      }

      // Title = "Asset ID — hostname".
      $host = trim((string) ($values['field_it_hostname'] ?? ''));
      $entity->set('title', $host !== '' ? "$assetId — $host" : $assetId);

      $entity->save();
      $tally[$isNew ? 'created' : 'updated']++;
    }
    catch (\Throwable $e) {
      $tally['errors']++;
      $this->loggerFactory->get('bos_it_import')->error(
        'IT import failed for @asset: @msg',
        ['@asset' => $assetId, '@msg' => $e->getMessage()]
      );
    }
  }

  private function toDecimal(string $v): ?string {
    $v = trim($v);
    if ($v === '' || !is_numeric($v)) {
      return NULL;
    }
    return (string) round((float) $v, 2);
  }

  private function toBool(string $v): ?bool {
    $v = strtolower(trim($v));
    if ($v === '') {
      return NULL;
    }
    return in_array($v, ['yes', 'true', '1', 'enabled', 'on'], TRUE);
  }

}
