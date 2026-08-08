<?php

namespace Drupal\wo_photo_split\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush command to split pre-existing multi-image wo_images media.
 *
 * New uploads split automatically via the module's insert/update hooks; this
 * migration handles the batches created before the module existed. Idempotent:
 * once every wo_images holds a single photo, re-running is a no-op.
 */
class WoPhotoSplitCommands extends DrushCommands {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
  }

  /**
   * Split existing multi-image wo_images media into one media per photo.
   *
   * @command wo:photos:split
   * @option apply Perform the split. Without this it is a dry run (reports only).
   * @option limit Max media to process (0 = all).
   * @aliases wo-photos-split
   * @usage drush wo:photos:split
   *   Dry run — report how many media would split and how many would be created.
   * @usage drush wo:photos:split --apply
   *   Perform the split.
   */
  public function split(array $options = ['apply' => FALSE, 'limit' => 0]) {
    $apply = (bool) $options['apply'];
    $limit = (int) $options['limit'];
    $storage = $this->entityTypeManager->getStorage('media');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'wo_images')
      ->execute();

    // Identify the multi-image batches.
    $toSplit = [];
    $totalExtra = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $m) {
        $n = $m->hasField('field_media_image_1') ? $m->get('field_media_image_1')->count() : 0;
        if ($n > 1) {
          $toSplit[$m->id()] = $n;
          $totalExtra += ($n - 1);
        }
      }
      $storage->resetCache($chunk);
    }

    $this->io()->writeln(sprintf('wo_images media total:            %d', count($ids)));
    $this->io()->writeln(sprintf('multi-image media to split:       %d', count($toSplit)));
    $this->io()->writeln(sprintf('new photo media to be created:    %d', $totalExtra));
    $this->io()->writeln(sprintf('wo_images media after split:      %d', count($ids) + $totalExtra));

    if (!$apply) {
      $this->io()->warning('DRY RUN — nothing changed. Re-run with --apply to perform the split.');
      return;
    }

    $processed = 0;
    $createdTotal = 0;
    foreach (array_keys($toSplit) as $mid) {
      $m = $storage->load($mid);
      if (!$m) {
        continue;
      }
      $createdTotal += _wo_photo_split_maybe_split($m);
      $processed++;
      if ($processed % 100 === 0) {
        $this->io()->writeln(sprintf('  ...%d/%d media split, %d new so far', $processed, count($toSplit), $createdTotal));
        $storage->resetCache();
      }
      if ($limit && $processed >= $limit) {
        break;
      }
    }
    $this->io()->success(sprintf('Split %d media into %d new photo media.', $processed, $createdTotal));
  }

}
