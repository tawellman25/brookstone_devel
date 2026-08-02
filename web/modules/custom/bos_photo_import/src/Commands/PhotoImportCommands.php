<?php

declare(strict_types=1);

namespace Drupal\bos_photo_import\Commands;

use Drupal\bos_photo_import\Service\PropertyPhotoImportService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush command — import archive photos/videos into property media from the
 * photo-property association mapping CSV. Thin CLI over
 * PropertyPhotoImportService. Idempotent; confidence-gated publishing.
 */
final class PhotoImportCommands extends DrushCommands {

  public function __construct(
    private readonly PropertyPhotoImportService $importService,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct();
  }

  /**
   * Import property photos/videos from the association mapping.
   *
   * @command bos_photo_import:import
   * @aliases photo:import
   * @param string $csv_path   Path to Photo_Property_Associations CSV.
   * @param string $media_root Directory mapping to the "_Customers" root (source files).
   * @option limit Only import up to N new media (0 = all). For testing.
   * @option type  all | image | video (default all).
   * @usage drush photo:import /path/mapping.csv /mnt/photos --limit=20 --type=image
   *   Test-import 20 archive images in DDEV.
   */
  public function import(string $csv_path, string $media_root, array $options = ['limit' => 0, 'type' => 'all']): int {
    $logger = $this->loggerFactory->get('bos_photo_import');
    $this->output()->writeln("Importing from {$csv_path} (source root: {$media_root})");

    $r = $this->importService->importFromCsv($csv_path, $media_root, [
      'limit' => (int) $options['limit'],
      'type' => $options['type'],
    ]);

    if (in_array($r['status'], ['unreadable', 'parse_error'], TRUE)) {
      $this->output()->writeln("<error>{$r['message']}</error>");
      $logger->error($r['message']);
      return self::EXIT_FAILURE;
    }

    $summary = sprintf(
      'Photo import: rows=%d created=%d (published=%d, unpublished=%d) | skipped: dupe=%d no_pid=%d type=%d | missing_file=%d no_property=%d errors=%d',
      $r['rows'], $r['created'], $r['published'], $r['unpublished'],
      $r['skipped_dupe'], $r['skipped_no_property_id'], $r['skipped_type'],
      $r['missing_file'], $r['no_such_property'], $r['errors']
    );
    $this->output()->writeln($summary);
    $logger->info($summary);
    return self::EXIT_SUCCESS;
  }

}
