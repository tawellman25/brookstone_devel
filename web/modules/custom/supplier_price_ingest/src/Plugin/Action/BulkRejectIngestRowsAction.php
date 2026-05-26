<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Plugin\Action;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Phase 3.7 — Bulk Reject for supplier_price_ingest_row entities.
 *
 * @Action(
 *   id = "supplier_price_ingest_bulk_reject_rows",
 *   label = @Translation("Bulk Reject Ingest Rows"),
 *   category = @Translation("Supplier Price Ingest"),
 *   confirm = TRUE
 * )
 *
 * Available from both Discovery Queue and Fuzzy Match Review views.
 * Same effect as RejectRowForm Operation D applied per row — useful
 * for clearing obvious-junk rows (e.g., apparel mixed into an
 * irrigation supplier's catalog scrape).
 */
class BulkRejectIngestRowsAction extends ViewsBulkOperationsActionBase {

  use MessengerTrait;

  public function execute(EntityInterface $entity = NULL): void {
    if (!$entity || $entity->getEntityTypeId() !== 'supplier_price_ingest_row') {
      return;
    }
    $currentUser = \Drupal::currentUser();

    $entity->set('field_row_status', 'rejected');
    $entity->set('field_resolution_action', 'rejected');
    $entity->set('field_resolved_by', (int) $currentUser->id());
    $entity->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));

    $existing = trim((string) ($entity->get('field_resolution_notes')->value ?? ''));
    $line = sprintf('Bulk-rejected by %s on %s.', $currentUser->getDisplayName(), date('m/d/Y g:i A'));
    $entity->set('field_resolution_notes', $existing === '' ? $line : ($existing . "\n" . $line));
    $entity->save();
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
