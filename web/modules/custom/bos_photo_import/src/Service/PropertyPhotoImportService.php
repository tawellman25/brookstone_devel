<?php

declare(strict_types=1);

namespace Drupal\bos_photo_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileRepositoryInterface;

/**
 * Imports archive photos/videos into property_photo / property_video media from
 * the Photo_Property_Associations mapping CSV.
 *
 * - Only rows WITH a property_id are imported.
 * - Idempotent on field_original_path (the source FullPath) — re-runs skip
 *   already-imported files, so no duplicate managed files / S3 objects.
 * - Confidence gate: PUBLISHED only when the match is trustworthy for a public,
 *   indexed page — i.e. NOT a fuzzy-customer match AND NOT Low confidence.
 *   Fuzzy / Low matches are imported UNPUBLISHED for review.
 * - Files are written through Drupal's file API to public://property-photos/{id}
 *   (or property-videos/{id}); on live that means s3fs writes them to S3 the
 *   same way every BOS upload does.
 */
final class PropertyPhotoImportService {

  private const FUZZY_METHODS = ['customer-fuzzy', 'customer-fuzzy-multi'];

  /** Property lookup cache: id => [nickname, full_address] | false. */
  private array $propCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * @param string $csvPath   Mapping CSV path.
   * @param string $mediaRoot Directory that maps to the "_Customers" root
   *                          (e.g. /mnt/photos in DDEV, or a staging dir on live).
   * @param array  $opts      ['limit' => int, 'type' => 'all'|'image'|'video'].
   *
   * @return array Tally + status.
   */
  public function importFromCsv(string $csvPath, string $mediaRoot, array $opts = []): array {
    if (!is_file($csvPath) || !is_readable($csvPath)) {
      return ['status' => 'unreadable', 'message' => "CSV not found/readable: {$csvPath}"];
    }
    $limit = (int) ($opts['limit'] ?? 0);
    $typeFilter = $opts['type'] ?? 'all';
    $mediaRoot = rtrim($mediaRoot, '/');

    $fh = fopen($csvPath, 'r');
    $header = fgetcsv($fh);
    if (!$header) {
      fclose($fh);
      return ['status' => 'parse_error', 'message' => 'Empty CSV'];
    }
    // Normalize headers. A UTF-8 BOM sits BEFORE the first field's opening
    // quote, which breaks fgetcsv's enclosure handling for that one cell — it
    // arrives as "\xEF\xBB\xBF\"Type\"". Strip the BOM AND any stray
    // surrounding quotes/whitespace so keys like "Type" match reliably.
    $header = array_map(
      static fn($h) => trim(str_replace("\xEF\xBB\xBF", '', (string) $h), " \t\n\r\0\x0B\""),
      $header
    );
    $idx = array_flip($header);

    $t = [
      'rows' => 0, 'created' => 0, 'published' => 0, 'unpublished' => 0,
      'skipped_dupe' => 0, 'skipped_no_property_id' => 0, 'skipped_type' => 0,
      'missing_file' => 0, 'no_such_property' => 0, 'errors' => 0,
    ];

    $get = fn(array $r, string $c) => isset($idx[$c]) ? trim((string) ($r[$idx[$c]] ?? '')) : '';

    while (($row = fgetcsv($fh)) !== FALSE) {
      if ($limit && $t['created'] >= $limit) {
        break;
      }
      $t['rows']++;

      $propId = $get($row, 'property_id');
      if ($propId === '' || !ctype_digit($propId)) {
        $t['skipped_no_property_id']++;
        continue;
      }
      $propId = (int) $propId;

      $type = strtolower($get($row, 'Type')) === 'video' ? 'video' : 'image';
      if ($typeFilter !== 'all' && $typeFilter !== $type) {
        $t['skipped_type']++;
        continue;
      }

      $fullPath = $get($row, 'FullPath');
      if ($fullPath === '') {
        $t['errors']++;
        continue;
      }

      // Idempotency: already imported?
      $bundle = $type === 'video' ? 'property_video' : 'property_photo';
      $existing = $this->entityTypeManager->getStorage('media')->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', $bundle)
        ->condition('field_original_path', $fullPath)
        ->range(0, 1)->execute();
      if ($existing) {
        $t['skipped_dupe']++;
        continue;
      }

      // Property must exist.
      $prop = $this->property($propId);
      if (!$prop) {
        $t['no_such_property']++;
        continue;
      }

      // Resolve the readable source path from the Windows FullPath.
      $src = $this->resolveSource($fullPath, $mediaRoot);
      if ($src === NULL || !is_file($src)) {
        $t['missing_file']++;
        continue;
      }

      $method = $get($row, 'MatchMethod');
      $conf = $get($row, 'Confidence');
      $publish = !in_array($method, self::FUZZY_METHODS, TRUE) && strcasecmp($conf, 'Low') !== 0;

      try {
        $this->createMedia($type, $bundle, $src, $propId, $prop, [
          'source_customer' => $get($row, 'SourceCustomer'),
          'date_taken' => $get($row, 'DateTaken'),
          'method' => $method,
          'confidence' => $conf,
          'original_path' => $fullPath,
          'publish' => $publish,
        ]);
        $t['created']++;
        $t[$publish ? 'published' : 'unpublished']++;
      }
      catch (\Throwable $e) {
        $t['errors']++;
        $this->loggerFactory->get('bos_photo_import')->error(
          'Import failed for @f: @m', ['@f' => $fullPath, '@m' => $e->getMessage()]
        );
      }
    }
    fclose($fh);

    return ['status' => 'imported'] + $t;
  }

  /**
   * Create + save one media entity (copies the file through the file API).
   */
  private function createMedia(string $type, string $bundle, string $src, int $propId, $prop, array $meta): void {
    $filename = basename($src);
    $subdir = $type === 'video' ? 'property-videos' : 'property-photos';
    $dir = "public://$subdir/$propId";
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $data = file_get_contents($src);
    if ($data === FALSE) {
      throw new \RuntimeException("could not read $src");
    }
    // writeData -> stream wrapper (public:// = local in DDEV, S3 via s3fs on live).
    $file = $this->fileRepository->writeData($data, "$dir/$filename", FileExists::Rename);

    // SEO alt/label text from the property.
    [$nickname, $address] = $prop;
    $altBits = array_filter([$nickname, $address]);
    $alt = $altBits ? implode(' — ', $altBits) : ("Property #$propId");

    $values = [
      'bundle' => $bundle,
      'name' => trim(($nickname ?: "Property #$propId") . ' — ' . $filename),
      // Published so staff can see/review it; the PUBLIC gallery gate is the
      // separate field_public_ok flag (on only for confident matches).
      'status' => 1,
      'field_public_ok' => ['value' => $meta['publish'] ? 1 : 0],
      'field_property' => ['target_id' => $propId],
      'field_source_customer' => $meta['source_customer'],
      'field_match_method' => $meta['method'],
      'field_match_confidence' => $meta['confidence'],
      'field_original_path' => $meta['original_path'],
    ];
    if ($type === 'video') {
      $values['field_media_video_file_1'] = ['target_id' => $file->id()];
    }
    else {
      $values['field_media_image_1'] = ['target_id' => $file->id(), 'alt' => $alt];
    }
    // Date taken (YYYY-MM-DD) into the date field.
    $dt = $this->normalizeDate($meta['date_taken']);
    if ($dt !== NULL) {
      $values['field_date_taken'] = $dt;
    }

    $this->entityTypeManager->getStorage('media')->create($values)->save();
  }

  /**
   * Property nickname + full address (cached). FALSE if the property is gone.
   *
   * @return array{0:string,1:string}|false
   */
  private function property(int $id) {
    if (array_key_exists($id, $this->propCache)) {
      return $this->propCache[$id];
    }
    $p = $this->entityTypeManager->getStorage('properties')->load($id);
    if (!$p) {
      return $this->propCache[$id] = FALSE;
    }
    $nick = $p->hasField('field_nickname') ? trim((string) ($p->get('field_nickname')->value ?? '')) : '';
    $addr = $p->hasField('field_full_address') ? trim((string) ($p->get('field_full_address')->value ?? '')) : '';
    return $this->propCache[$id] = [$nick, $addr];
  }

  /**
   * Convert a Windows "…\_Customers\A\Cust\file.jpg" path to a readable path
   * under $mediaRoot (which maps to the _Customers root).
   */
  private function resolveSource(string $fullPath, string $mediaRoot): ?string {
    $norm = str_replace('\\', '/', $fullPath);
    $pos = stripos($norm, '/_Customers/');
    if ($pos === FALSE) {
      return NULL;
    }
    $rel = ltrim(substr($norm, $pos + strlen('/_Customers/')), '/');
    return $rel === '' ? NULL : "$mediaRoot/$rel";
  }

  private function normalizeDate(string $v): ?string {
    $v = trim($v);
    if ($v === '') {
      return NULL;
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : NULL;
  }

}
