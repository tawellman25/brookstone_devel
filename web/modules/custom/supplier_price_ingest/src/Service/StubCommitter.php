<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Phase 3.5 stub commit pathway.
 *
 * The real commit pipeline ships in Phase 3.6 — that's where catalog
 * mutations (`material_suppliers` creates/updates, `material_price_history`
 * writes) actually happen. In 3.5 we want the dry-run report's
 * Approve button to look successful end-to-end without any catalog
 * mutation, so this service:
 *
 *   1. Transitions the batch from `approved` → `committed`.
 *   2. Marks every auto-applying row (tier_1_mfr / tier_2_supplier_sku
 *      / tier_3_fuzzy_high) as field_row_status = 'committed' so the
 *      committed-state report displays correct row state.
 *   3. Logs an INFO line that names the user and notes that no real
 *      catalog mutation occurred.
 *
 * When Phase 3.6 lands, this service is replaced by a real commit
 * service (`CommitService` or similar) — the approve form will swap
 * the service ID. The stub deliberately mirrors the eventual contract
 * so the swap is mechanical.
 */
final class StubCommitter {

  /**
   * Tiers that auto-apply at commit. Mirrors the spec's Tier-1, Tier-2,
   * Tier-3-high routing.
   */
  private const AUTO_APPLY_TIERS = [
    'tier_1_mfr',
    'tier_2_supplier_sku',
    'tier_3_fuzzy_high',
  ];

  /**
   * Row-storage chunk size for the field_row_status update.
   */
  private const CHUNK_SIZE = 100;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Stub-commit a batch that's currently in 'approved' status.
   *
   * @throws \RuntimeException
   *   If the batch isn't in 'approved' status.
   */
  public function commit(EntityInterface $batch, AccountInterface $user): void {
    $status = (string) ($batch->get('field_status')->value ?? '');
    if ($status !== 'approved') {
      throw new \RuntimeException(sprintf(
        'StubCommitter::commit refused batch %d — expected status "approved", got "%s".',
        $batch->id(),
        $status,
      ));
    }

    $rowStorage = $this->entityTypeManager->getStorage('supplier_price_ingest_row');
    $rowIds = $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batch->id())
      ->condition('field_match_tier', self::AUTO_APPLY_TIERS, 'IN')
      ->sort('id', 'ASC')
      ->execute();

    $stamped = 0;
    foreach (array_chunk(array_values($rowIds), self::CHUNK_SIZE) as $chunk) {
      $rows = $rowStorage->loadMultiple($chunk);
      foreach ($rows as $row) {
        $row->set('field_row_status', 'committed');
        $row->save();
        $stamped++;
      }
      $rowStorage->resetCache(array_keys($rows));
    }

    $batch->set('field_status', 'committed');
    $batch->save();

    $this->loggerFactory->get('supplier_price_ingest')->info(
      'Batch @bid approved by user @uid. Phase 3.5 STUB COMMIT: marked @stamped rows as field_row_status=committed; no material_suppliers / material_price_history mutations occurred. Real commit logic ships in Phase 3.6.',
      [
        '@bid' => $batch->id(),
        '@uid' => $user->id(),
        '@stamped' => $stamped,
      ],
    );
  }

}
