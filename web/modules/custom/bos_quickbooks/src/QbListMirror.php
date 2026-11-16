<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The qb_list mirror: one entity per QuickBooks list record, keyed by ListID.
 *
 * upsert() writes only when something QuickBooks owns changed (EditSequence,
 * TimeModified, name, active flag…) or the record has not been stamped for a
 * day — so an hourly sync of 3,500 unchanged customers costs reads, not
 * 3,500 entity saves. Fields BOS owns on a customer (the client link, match
 * status and note) are never touched by a sync.
 *
 * markMissing() runs once a list's query has completed: every mirror row of
 * that bundle whose ListID the sync did not return is flagged Missing (deleted
 * or merged away in QuickBooks) and kept — BOS never deletes history.
 */
final class QbListMirror {

  public const ENTITY_TYPE = 'qb_list';
  public const RESTAMP_AFTER = 86400;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return string
   *   created | updated | unchanged
   */
  public function upsert(string $bundle, array $rec, int $seenAt): string {
    if (($rec['list_id'] ?? '') === '') {
      throw new \InvalidArgumentException('Record without a ListID.');
    }
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $entity = $this->findByListId($bundle, $rec['list_id']);
    $created = FALSE;
    if (!$entity) {
      $entity = $storage->create(['type' => $bundle, 'field_list_id' => $rec['list_id']]);
      if ($bundle === 'customer') {
        $entity->set('field_match_status', 'unmatched');
      }
      $created = TRUE;
    }
    $values = [
      'title' => mb_substr((string) ($rec['full_name'] ?: $rec['name'] ?: $rec['list_id']), 0, 255),
      'status' => !empty($rec['is_active']) ? 1 : 0,
      'field_name' => mb_substr((string) $rec['name'], 0, 255),
      'field_edit_sequence' => mb_substr((string) $rec['edit_sequence'], 0, 32),
      'field_sublevel' => (int) ($rec['sublevel'] ?? 0),
      'field_parent_list_id' => (string) ($rec['parent_list_id'] ?? ''),
      'field_time_created' => $rec['time_created'] ?? NULL,
      'field_time_modified' => $rec['time_modified'] ?? NULL,
      'field_missing' => 0,
    ];
    if ($bundle === 'customer') {
      $values += [
        'field_first_name' => mb_substr((string) ($rec['first_name'] ?? ''), 0, 64),
        'field_last_name' => mb_substr((string) ($rec['last_name'] ?? ''), 0, 64),
        'field_company_name' => mb_substr((string) ($rec['company_name'] ?? ''), 0, 128),
        'field_customer_type' => mb_substr((string) ($rec['customer_type'] ?? ''), 0, 64),
        'field_terms' => mb_substr((string) ($rec['terms'] ?? ''), 0, 64),
        'field_bill_address' => (string) ($rec['bill_address'] ?? ''),
      ];
    }
    elseif ($bundle === 'item') {
      $values += [
        'field_item_type' => mb_substr((string) ($rec['item_type'] ?? ''), 0, 32),
        'field_income_account' => mb_substr((string) ($rec['income_account'] ?? ''), 0, 128),
        'field_price' => $rec['price'] ?? NULL,
        'field_description' => (string) ($rec['description'] ?? ''),
      ];
    }
    $changed = $created;
    foreach ($values as $field => $value) {
      if (!$entity->hasField($field)) {
        continue;
      }
      $current = $entity->get($field)->isEmpty() ? NULL : $entity->get($field)->value;
      if ($this->differs($current, $value)) {
        $entity->set($field, $value);
        $changed = TRUE;
      }
    }
    $last_seen = $entity->get('field_last_seen')->isEmpty() ? NULL : $entity->get('field_last_seen')->value;
    $last_seen_ts = $last_seen ? strtotime($last_seen . ' UTC') : 0;
    if ($changed || ($seenAt - (int) $last_seen_ts) >= self::RESTAMP_AFTER) {
      $entity->set('field_last_seen', gmdate('Y-m-d\TH:i:s', $seenAt));
      $entity->_bos_qb_sync = TRUE;
      $entity->save();
      return $created ? 'created' : 'updated';
    }
    return 'unchanged';
  }

  /**
   * Flags rows of a bundle the completed sync did not return.
   *
   * @param string[] $seenListIds
   *   Every ListID the sync returned for this bundle.
   *
   * @return int
   *   Rows newly marked Missing.
   */
  public function markMissing(string $bundle, array $seenListIds): int {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', $bundle);
    $group = $query->orConditionGroup()
      ->condition('field_missing', 1, '<>')
      ->notExists('field_missing');
    $ids = $query->condition($group)->execute();
    $marked = 0;
    $seen = array_fill_keys($seenListIds, TRUE);
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $entity) {
        $list_id = (string) $entity->get('field_list_id')->value;
        if (isset($seen[$list_id])) {
          continue;
        }
        $entity->set('field_missing', 1);
        $entity->_bos_qb_sync = TRUE;
        $entity->save();
        $marked++;
        $this->logger->notice('QuickBooks @bundle "@name" (@id) is no longer returned by QuickBooks — marked Missing.', [
          '@bundle' => $bundle,
          '@name' => $entity->label(),
          '@id' => $list_id,
        ]);
      }
    }
    return $marked;
  }

  public function findByListId(string $bundle, string $listId): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('field_list_id', $listId)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $entity = $storage->load(reset($ids));
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Per-bundle totals for the status page.
   *
   * @return array<string, array{total:int,active:int,missing:int}>
   */
  public function counts(): array {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $out = [];
    foreach (array_keys(QbXml::LISTS) as $bundle) {
      $base = fn() => $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->count();
      $out[$bundle] = [
        'total' => (int) $base()->execute(),
        'active' => (int) $base()->condition('status', 1)->condition('field_missing', 1, '<>')->execute(),
        'missing' => (int) $base()->condition('field_missing', 1)->execute(),
      ];
    }
    return $out;
  }

  /**
   * Customer match-status breakdown (active, present customers only).
   *
   * @return array<string,int>
   */
  public function matchCounts(): array {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $out = [];
    foreach (['unmatched', 'auto', 'manual', 'ambiguous', 'duplicate', 'ignored'] as $status) {
      $out[$status] = (int) $storage->getQuery()->accessCheck(FALSE)
        ->condition('type', 'customer')
        ->condition('status', 1)
        ->condition('field_missing', 1, '<>')
        ->condition('field_match_status', $status)
        ->count()->execute();
    }
    return $out;
  }

  /**
   * Writes the customer's ListID onto its BOS client's customer profile, and
   * clears it from the client it used to point at.
   *
   * The profile field is a derived copy for the export's convenience; the
   * qb_list customer's field_bos_client is the source of truth.
   */
  public function syncClientProfile(ContentEntityInterface $customer, ?int $oldUid): void {
    $list_id = (string) $customer->get('field_list_id')->value;
    $new_uid = $customer->get('field_bos_client')->isEmpty() ? NULL : (int) $customer->get('field_bos_client')->target_id;
    if ($oldUid && $oldUid !== $new_uid) {
      $profile = $this->customerProfile($oldUid);
      if ($profile && (string) $profile->get('field_qb_list_id')->value === $list_id) {
        $profile->set('field_qb_list_id', NULL);
        $profile->_bos_qb_sync = TRUE;
        $profile->save();
      }
    }
    if ($new_uid) {
      $profile = $this->customerProfile($new_uid);
      if (!$profile) {
        $this->logger->warning('QuickBooks customer "@name" linked to user @uid, who has no customer profile — ListID not written.', ['@name' => $customer->label(), '@uid' => $new_uid]);
        return;
      }
      if ((string) $profile->get('field_qb_list_id')->value !== $list_id) {
        $profile->set('field_qb_list_id', $list_id);
        $profile->_bos_qb_sync = TRUE;
        $profile->save();
      }
    }
  }

  /**
   * Other active customers already linked to this client (duplicate check).
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function otherCustomersLinkedTo(int $uid, ?int $exceptId): array {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'customer')
      ->condition('field_bos_client', $uid)
      ->condition('field_missing', 1, '<>');
    if ($exceptId) {
      $query->condition('id', $exceptId, '<>');
    }
    $ids = $query->execute();
    return $ids ? array_values($storage->loadMultiple($ids)) : [];
  }

  private function customerProfile(int $uid): ?ContentEntityInterface {
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties(['uid' => $uid, 'type' => 'customer_profile']);
    $profile = $profiles ? reset($profiles) : NULL;
    return $profile instanceof ContentEntityInterface && $profile->hasField('field_qb_list_id') ? $profile : NULL;
  }

  private function differs(mixed $current, mixed $new): bool {
    if ($current === NULL && ($new === NULL || $new === '')) {
      return FALSE;
    }
    if (is_numeric($current) && is_numeric($new)) {
      return (float) $current !== (float) $new;
    }
    return (string) $current !== (string) $new;
  }

}
