<?php

declare(strict_types=1);

namespace Drupal\bos_it_import\Commands;

use Drupal\bos_it_import\Service\ItEquipmentImportService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush command — import IT assets from the Office Network Baseline workbook
 * into equipment:it_equipment. Thin CLI wrapper; all work is on
 * ItEquipmentImportService::importFromFile(). Idempotent on Asset ID.
 *
 * Exit-code policy mirrors bos_wex_import: failure ONLY on file-level problems
 * (unreadable / parse error). Per-row skips/errors are summarised, not fatal.
 */
final class ItImportCommands extends DrushCommands {

  public function __construct(
    private readonly ItEquipmentImportService $importService,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct();
  }

  /**
   * Import IT assets from a baseline CSV/XLSX.
   *
   * @command bos_it_import:import
   * @aliases it:import
   * @param string $filepath
   *   Absolute or working-directory-relative path to the baseline .xlsx/.csv.
   * @usage drush it:import /path/to/Brookstone_Office_Network_Baseline_v1.1.xlsx
   *   Import (create/update) equipment:it_equipment records, idempotent on Asset ID.
   */
  public function import(string $filepath): int {
    $logger = $this->loggerFactory->get('bos_it_import');
    $this->output()->writeln("Reading IT baseline: {$filepath}");

    $result = $this->importService->importFromFile($filepath);

    if (in_array($result['status'], ['unreadable', 'parse_error'], TRUE)) {
      $this->output()->writeln("<error>{$result['message']}</error>");
      $logger->error($result['message']);
      return self::EXIT_FAILURE;
    }

    $summary = sprintf(
      'IT import complete — rows=%d, created=%d, updated=%d, skipped=%d, errors=%d.',
      $result['rows'] ?? 0,
      $result['created'] ?? 0,
      $result['updated'] ?? 0,
      $result['skipped'] ?? 0,
      $result['errors'] ?? 0
    );
    $this->output()->writeln($summary);
    $logger->info($summary);
    return self::EXIT_SUCCESS;
  }

}
