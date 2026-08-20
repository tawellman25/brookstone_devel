<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolve service_request_status terms by NAME → TID (per-request cache).
 *
 * Status terms are content; their TIDs differ per environment (dev vs live), so
 * code must never hardcode one. Use the name constants below.
 */
final class ServiceRequestStatusResolver {

  public const VID = 'service_request_status';

  public const NEW = 'New';
  public const NEEDS_REVIEW = 'Needs Review';
  public const VERIFIED = 'Verified';
  public const ALREADY_COVERED = 'Already Covered';
  public const DUPLICATE = 'Duplicate';
  public const REJECTED = 'Rejected';
  public const CONVERTED = 'Converted';

  /** @var array<string,int> */
  private array $cache = [];

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Term id for a status name. Throws if the term is missing (seed not run).
   */
  public function tid(string $name): int {
    if (isset($this->cache[$name])) {
      return $this->cache[$name];
    }
    $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VID)
      ->condition('name', $name)
      ->sort('tid', 'ASC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      throw new \RuntimeException(sprintf('service_request_status term "%s" not found — run seed_service_request_status.php.', $name));
    }
    return $this->cache[$name] = (int) reset($ids);
  }

  /**
   * TIDs for several names at once.
   *
   * @param string[] $names
   *
   * @return int[]
   */
  public function tids(array $names): array {
    return array_map([$this, 'tid'], $names);
  }

}
