<?php

declare(strict_types=1);

namespace Drupal\bos_homepage\Commands;

use Drupal\Core\File\FileExists;
use Drush\Commands\DrushCommands;

/**
 * Imports homepage portfolio photos from address-named project folders.
 *
 * Folder convention: <source>/<street address> - <Town>/  (e.g.
 * "15635 Fire Mountain Rd - Paonia"). The town after " - " becomes the caption.
 * Each photo is resized to 1200px wide, saved to public://homepage-portfolio/
 * (→ S3 on live), and recorded in bos_homepage.settings:portfolio, which the
 * Proof band renders (the band is hidden until this runs).
 *
 * On live: stage the Photos folder on the server and pass --source=<path>
 * (the /mnt/d dev path does not exist there).
 */
class PortfolioImportCommands extends DrushCommands {

  /**
   * Import homepage portfolio photos from project folders.
   *
   * @command bos_homepage:portfolio-import
   * @aliases bos-hp-portfolio
   * @option source Directory holding the "<address> - <Town>" project folders.
   * @option per-folder Max photos to take per project folder.
   * @option dry-run Report what would be imported without writing anything.
   */
  public function import(array $options = ['source' => '/mnt/d/_Brookstone/_08_Marketing/Photos', 'per-folder' => 2, 'dry-run' => FALSE]): void {
    $source = rtrim((string) $options['source'], '/');
    $perFolder = max(1, (int) $options['per-folder']);
    $dry = (bool) $options['dry-run'];

    if (!is_dir($source)) {
      $this->logger()->error("Source directory not found: $source");
      return;
    }
    $this->output()->writeln(($dry ? 'DRY RUN — ' : '') . "scanning $source (per-folder $perFolder)");

    $fileSystem = \Drupal::service('file_system');
    $fileRepo = \Drupal::service('file.repository');
    $destDir = 'public://homepage-portfolio';
    if (!$dry) {
      $fileSystem->prepareDirectory($destDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
    }

    $portfolio = [];
    $folders = glob($source . '/*', GLOB_ONLYDIR) ?: [];
    sort($folders);
    foreach ($folders as $folder) {
      $base = basename($folder);
      // Town = text after the last " - "; fall back to the whole name.
      $town = (strpos($base, ' - ') !== FALSE) ? trim(substr($base, strrpos($base, ' - ') + 3)) : $base;
      $photos = [];
      foreach (glob($folder . '/*.{jpg,jpeg,JPG,JPEG,png,PNG}', GLOB_BRACE) ?: [] as $p) {
        if (strpos(basename($p), 'thumb_') === 0) {
          continue;
        }
        $photos[] = $p;
      }
      sort($photos);
      $take = array_slice($photos, 0, $perFolder);
      $this->output()->writeln("  {$base}  (town: {$town}) — " . count($photos) . " photos, taking " . count($take));
      foreach ($take as $i => $src) {
        $alt = "Landscape project in {$town}, Colorado by Brookstone Outdoors";
        if ($dry) {
          $portfolio[] = ['fid' => 0, 'town' => $town, 'alt' => $alt];
          continue;
        }
        $blob = $this->resizeToJpeg($src, 1200);
        if ($blob === NULL) {
          $this->logger()->warning("  skipped (unreadable): " . basename($src));
          continue;
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($base));
        $filename = $slug . '-' . ($i + 1) . '.jpg';
        $file = $fileRepo->writeData($blob, $destDir . '/' . $filename, FileExists::Replace);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $portfolio[] = ['fid' => (int) $file->id(), 'town' => $town, 'alt' => $alt];
        }
      }
    }

    if ($dry) {
      $this->output()->writeln("would record " . count($portfolio) . " portfolio images (dry run — nothing written)");
      return;
    }
    \Drupal::configFactory()->getEditable('bos_homepage.settings')->set('portfolio', $portfolio)->save();
    drupal_flush_all_caches();
    $this->logger()->success("Imported " . count($portfolio) . " portfolio images. The Proof band will now render.");
  }

  /**
   * Read an image, scale to $maxWidth, return JPEG bytes (or NULL).
   */
  private function resizeToJpeg(string $path, int $maxWidth): ?string {
    $info = @getimagesize($path);
    if (!$info) {
      return NULL;
    }
    $img = match ($info[2]) {
      IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
      IMAGETYPE_PNG => @imagecreatefrompng($path),
      default => NULL,
    };
    if (!$img) {
      return NULL;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $tw = min($maxWidth, $w);
    $th = (int) round($h * $tw / $w);
    $t = imagecreatetruecolor($tw, $th);
    imagecopyresampled($t, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    ob_start();
    imagejpeg($t, NULL, 82);
    $blob = ob_get_clean();
    imagedestroy($img);
    imagedestroy($t);
    return $blob ?: NULL;
  }

}
