<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Shared helpers for the per-row dashboard operation forms.
 *
 * Each form (Link, Override, Confirm, Create, MarkReplacement, Reject,
 * SendToDiscovery) needs:
 *   - the row summary block at the top of the form
 *   - a "Back to queue" link (Phase 3.7.5 UX — save-and-load-next)
 *   - the IngestRow row_data array shape that PriceSyncService::ingestRow expects
 *   - the resolution-notes append helper used by every operation
 *   - the "next pending row" redirect helper that powers save-and-load-next
 *
 * Extracting these once removes ~30 lines of duplication per form and
 * keeps the next-row logic single-sourced — so any future tweak to the
 * lookup order (e.g., respecting a "skip this row" flag) lands in one
 * place.
 */
trait IngestRowFormTrait {

  /**
   * Workflow context constants for the next-row redirect helper.
   *
   * Each constant pairs (field_row_status, field_match_tier) — both
   * filters must match for a row to be considered "pending" in that
   * workflow. Mirrors the filter conditions on
   * views.view.supplier_ingest_discovery_queue and
   * views.view.supplier_ingest_fuzzy_review.
   */
  protected const CTX_DISCOVERY    = 'discovery';
  protected const CTX_FUZZY_REVIEW = 'fuzzy_review';

  protected const CTX_STATUS_DISCOVERY    = 'discovery_pending';
  protected const CTX_STATUS_FUZZY_REVIEW = 'discovery_pending';
  /**
   * Primary tier identifier per context — used for equality checks
   * (e.g., RejectRowForm classifying the originating workflow). For
   * the multi-tier QUERY filter, see CTX_TIERS_FOR_QUERY.
   */
  protected const CTX_TIER_DISCOVERY      = 'discovery';
  protected const CTX_TIER_FUZZY_REVIEW   = 'tier_3_fuzzy_med';
  /**
   * All tier values that count as belonging to a given context —
   * used as the IN-condition for the next-row lookup. Fuzzy-review
   * covers tier_3_fuzzy_med (Phase 3.4) AND tier_1_5_title_substring
   * (Phase 3.7.6); both route through the same Fuzzy Match Review queue.
   */
  protected const CTX_TIERS_FOR_QUERY = [
    self::CTX_DISCOVERY    => ['discovery'],
    self::CTX_FUZZY_REVIEW => ['tier_3_fuzzy_med', 'tier_1_5_title_substring'],
  ];

  protected const QUEUE_URL_DISCOVERY    = '/admin/materials/supplier-ingest/discovery';
  protected const QUEUE_URL_FUZZY_REVIEW = '/admin/materials/supplier-ingest/fuzzy-review';

  /**
   * Render a context block at the top of the form so the reviewer
   * sees what they're acting on before they pick / confirm / submit.
   */
  protected function buildRowSummary(EntityInterface $row): array {
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    return [
      '#type' => 'item',
      '#title' => $this->t('Row'),
      '#markup' => $this->t(
        '<strong>Batch:</strong> @batch — <strong>Supplier:</strong> @supplier — <strong>Row #</strong>@n<br><strong>Description:</strong> <em>@desc</em><br><strong>SKU:</strong> @sku — <strong>Mfr Item #:</strong> @mfr<br><strong>Unit Cost:</strong> $@cost / @uom',
        [
          '@batch' => $batch ? $batch->label() : '(unknown)',
          '@supplier' => $supplier ? $supplier->label() : '(unknown)',
          '@n' => (int) ($row->get('field_row_number')->value ?? 0),
          '@desc' => (string) ($row->get('field_description')->value ?? ''),
          '@sku' => (string) ($row->get('field_supplier_sku')->value ?? '—'),
          '@mfr' => (string) ($row->get('field_manufacturer_item_number')->value ?? '—'),
          '@cost' => (string) ($row->get('field_unit_cost')->value ?? '0'),
          '@uom' => (string) ($row->get('field_cost_uom')->value ?? '—'),
        ],
      ),
    ];
  }

  /**
   * Translate the ingest row's fields into the row_data array that
   * PriceSyncService::ingestRow() expects.
   */
  protected function buildRowData(EntityInterface $row): array {
    return [
      'unit_cost'                => (float) ($row->get('field_unit_cost')->value ?? 0),
      'cost_uom'                 => (string) ($row->get('field_cost_uom')->value ?? ''),
      'supplier_sku'             => (string) ($row->get('field_supplier_sku')->value ?? ''),
      'manufacturer_item_number' => (string) ($row->get('field_manufacturer_item_number')->value ?? ''),
      'manufacturer_name'        => (string) ($row->get('field_manufacturer_name')->value ?? ''),
      'pack_quantity'            => (string) ($row->get('field_pack_quantity')->value ?? ''),
      'description'              => (string) ($row->get('field_description')->value ?? ''),
    ];
  }

  /**
   * Append a line to field_resolution_notes without clobbering prior
   * content (matcher score breakdown, parser note, etc.).
   */
  protected function appendNote(EntityInterface $row, string $line): void {
    $existing = trim((string) ($row->get('field_resolution_notes')->value ?? ''));
    $row->set(
      'field_resolution_notes',
      $existing === '' ? $line : ($existing . "\n" . $line),
    );
  }

  /**
   * "Back to queue" link render array — placed at the top of every
   * resolution form so reviewers can break out of the save-and-load-next
   * flow when they want to return to the queue mid-batch.
   *
   * @param string $context  self::CTX_DISCOVERY or self::CTX_FUZZY_REVIEW
   */
  protected function buildBackToQueueLink(string $context): array {
    [$queueUrl, $label] = match ($context) {
      self::CTX_FUZZY_REVIEW => [
        self::QUEUE_URL_FUZZY_REVIEW,
        '← Back to Fuzzy Match Review queue',
      ],
      default => [
        self::QUEUE_URL_DISCOVERY,
        '← Back to Discovery Queue',
      ],
    };
    return [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => ['class' => ['bos-ingest-back-to-queue']],
      'link' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromUserInput($queueUrl),
      ],
    ];
  }

  /**
   * Save-and-load-next redirect target. Returns a Url that either points
   * at the next pending row's same-operation form, or — when nothing
   * remains in that workflow — at the queue page with a side-effect
   * "All resolved" status message added to the messenger.
   *
   * Lookup order:
   *   1. Next pending row in the SAME batch with id > current row's id.
   *   2. Cross-batch fallback — lowest-id pending row globally
   *      (any batch, any id). On hit, a side-effect "Moving to next
   *      pending row in batch N" status message is added so the
   *      operator knows context just changed.
   *   3. Nothing left → queue URL with "All ... resolved" success.
   *
   * @param EntityInterface $currentRow
   *   The row just resolved. Already saved/mutated.
   * @param string $sameOperationRoute
   *   Route name of the form the user just submitted (e.g.
   *   'supplier_price_ingest.discovery_create_material'). The redirect
   *   target opens the SAME route on the next row's id.
   * @param string $context
   *   self::CTX_DISCOVERY or self::CTX_FUZZY_REVIEW. Drives the row
   *   filter (status + tier) and the fallback queue URL / message.
   * @param mixed $entityTypeManager
   *   Pass $this->entityTypeManager from the form. Trait can't safely
   *   DI services on its own, so each consuming form forwards its
   *   already-injected service.
   * @param mixed $messenger
   *   Pass $this->messenger() from the form. Used for the "Moving to
   *   next pending row" cross-batch hint and the "All resolved" wrap-up
   *   message.
   */
  protected function nextRowRedirect(
    EntityInterface $currentRow,
    string $sameOperationRoute,
    string $context,
    $entityTypeManager,
    $messenger,
  ): Url {
    [$status, $tiers, $queueUrl, $allResolvedMessage, $workflowLabel] = match ($context) {
      self::CTX_FUZZY_REVIEW => [
        self::CTX_STATUS_FUZZY_REVIEW,
        self::CTX_TIERS_FOR_QUERY[self::CTX_FUZZY_REVIEW],
        self::QUEUE_URL_FUZZY_REVIEW,
        'All fuzzy matches reviewed — none remaining in any batch.',
        'fuzzy match',
      ],
      default => [
        self::CTX_STATUS_DISCOVERY,
        self::CTX_TIERS_FOR_QUERY[self::CTX_DISCOVERY],
        self::QUEUE_URL_DISCOVERY,
        'All discovery rows resolved — none remaining in any batch.',
        'discovery',
      ],
    };

    $storage = $entityTypeManager->getStorage('supplier_price_ingest_row');
    $currentId = (int) $currentRow->id();
    $currentBatchId = $currentRow->get('field_batch')->isEmpty()
      ? 0
      : (int) $currentRow->get('field_batch')->target_id;

    // Step 1 — same batch, id > current.
    $nextId = 0;
    if ($currentBatchId > 0) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_row_status', $status)
        ->condition('field_match_tier', $tiers, 'IN')
        ->condition('field_batch', $currentBatchId)
        ->condition('id', $currentId, '>')
        ->sort('id', 'ASC')
        ->range(0, 1)
        ->execute();
      if ($ids) {
        $nextId = (int) reset($ids);
      }
    }

    // Step 2 — cross-batch fallback.
    $crossBatchHop = FALSE;
    $nextBatchId = 0;
    if ($nextId === 0) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_row_status', $status)
        ->condition('field_match_tier', $tiers, 'IN')
        ->sort('id', 'ASC')
        ->range(0, 1)
        ->execute();
      if ($ids) {
        $nextId = (int) reset($ids);
        $crossBatchHop = TRUE;
        // Sidecar lookup for the friendly message.
        $next = $storage->load($nextId);
        if ($next && !$next->get('field_batch')->isEmpty()) {
          $nextBatchId = (int) $next->get('field_batch')->target_id;
        }
      }
    }

    if ($nextId > 0) {
      if ($crossBatchHop) {
        $messenger->addStatus(t(
          'All @workflow rows resolved in this batch. Moving to next pending row in batch #@b.',
          ['@workflow' => $workflowLabel, '@b' => $nextBatchId ?: '(unknown)']
        ));
      }
      return Url::fromRoute(
        $sameOperationRoute,
        ['supplier_price_ingest_row' => $nextId],
      );
    }

    // Step 3 — nothing left in the workflow.
    $messenger->addStatus(t($allResolvedMessage));
    return Url::fromUserInput($queueUrl);
  }

  /**
   * Attach the per-row resolution-form library (keyboard shortcuts +
   * autofocus + modified-form Esc guard). Each consuming form's
   * buildForm() should call this on its returned $form array.
   */
  protected function attachRowFormLibrary(array &$form): void {
    $form['#attached']['library'][] = 'supplier_price_ingest/row_resolution_form';
  }

}
