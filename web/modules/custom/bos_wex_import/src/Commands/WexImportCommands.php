<?php

declare(strict_types=1);

namespace Drupal\bos_wex_import\Commands;

use Drupal\bos_wex_import\Service\WexFuelImportService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush command — channel-agnostic WEX fuel import from a local file path.
 *
 * All heavy lifting (parse / empty-window detect / header validate /
 * row loop / tally) lives on WexFuelImportService::importFromFile().
 * This command is a thin CLI presentation wrapper: argument handling,
 * stdout formatting, watchdog logging, and exit-code mapping.
 *
 * The same service method is reused by WexFetchEmailCommands so any
 * future change to import behavior lands in one place.
 *
 * Exit-code policy: failure ONLY on file-level problems (unreadable
 * file, parse error, missing required headers). Row-level errors and
 * unmatched transactions are normal — surfaced in the summary, not
 * raised as failures.
 */
final class WexImportCommands extends DrushCommands {

  public function __construct(
    private readonly WexFuelImportService $importService,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct();
  }

  /**
   * Import a WEX fuel-card transaction export from a local file path.
   *
   * @command bos_wex_import:import
   * @aliases wex:import
   * @param string $filepath
   *   Absolute or working-directory-relative path to a .csv or .xlsx
   *   WEX export.
   * @usage drush bos_wex_import:import /tmp/wex_export_2026-05-30.csv
   *   Import a WEX export, reusing WexFuelImportService for all work.
   * @usage drush wex:import /var/spool/wex/today.csv
   *   Same, via short alias.
   */
  public function import(string $filepath): int {
    $logger = $this->loggerFactory->get('bos_wex_import');

    $this->output()->writeln("Reading WEX export: {$filepath}");

    $result = $this->importService->importFromFile($filepath);

    switch ($result['status']) {
      case 'unreadable':
      case 'parse_error':
      case 'missing_headers':
        $this->output()->writeln("<error>{$result['message']}</error>");
        $logger->error($result['message']);
        return self::EXIT_FAILURE;

      case 'empty_window':
        $this->output()->writeln($result['message']);
        $logger->info('WEX import: empty window for file @path.', ['@path' => $filepath]);
        return self::EXIT_SUCCESS;

      case 'imported':
      default:
        $this->output()->writeln("Processing {$result['total']} row(s)…");
        $this->output()->writeln('');
        $this->output()->writeln($result['message']);
        if (!empty($result['error_rows'])) {
          $this->output()->writeln('');
          $this->output()->writeln('Errors (per-row):');
          foreach ($result['error_rows'] as $err) {
            $this->output()->writeln(sprintf('  - tx %s: %s', $err['transaction_id'], $err['message']));
          }
        }
        $logger->info('@summary (file=@path)', [
          '@summary' => $result['message'],
          '@path' => $filepath,
        ]);
        return self::EXIT_SUCCESS;
    }
  }

}
