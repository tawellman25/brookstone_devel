<?php

namespace Drupal\contract_residential;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Writes append-only contract_action_log rows.
 *
 * Invariants:
 * - Append-only; no updates.
 * - Logging must never break core workflows.
 */
final class ContractActionLogWriter {

  /**
   * Write a log row for a successful status transition.
   *
   * @param \Drupal\Core\Entity\EntityInterface $contract
   *   Contract entity (entity type: contracts).
   * @param string $action_key
   *   Machine-readable action key. Recommended: VBO action plugin id.
   * @param int $from_tid
   *   Prior contract_status term ID (0 if none).
   * @param int $to_tid
   *   New contract_status term ID.
   * @param bool $admin_override
   *   TRUE if administrator override path used.
   * @param string $actor
   *   Allowed values: staff|client|system (matches field_actor list).
   * @param string|null $context
   *   Optional context / reason / metadata. Keep short; not required.
   *
   * @return bool
   *   TRUE if saved, FALSE otherwise.
   */
  public static function write(
    EntityInterface $contract,
    string $action_key,
    int $from_tid,
    int $to_tid,
    bool $admin_override,
    string $actor = 'staff',
    ?string $context = NULL
  ): bool {

    // Safety: only for contracts.
    if ($contract->getEntityTypeId() !== 'contracts') {
      return FALSE;
    }

    // Ensure logging entity type exists.
    $entity_type_manager = \Drupal::entityTypeManager();
    $definition = $entity_type_manager->getDefinition('contract_action_log', FALSE);
    if (!$definition) {
      return FALSE;
    }

    $storage = $entity_type_manager->getStorage('contract_action_log');

    /** @var \Drupal\Core\Entity\EntityInterface $log */
    $log = $storage->create([
      'type' => 'log',
    ]);

    // Base field: who.
    $uid = (int) \Drupal::currentUser()->id();
    if ($log->hasField('uid')) {
      $log->set('uid', $uid);
    }

    // Required bundle fields.
    if (!$log->hasField('field_contract')
      || !$log->hasField('field_action')
      || !$log->hasField('field_to_status')
      || !$log->hasField('field_admin_override')
      || !$log->hasField('field_actor')
    ) {
      return FALSE;
    }

    $log->set('field_contract', $contract->id());
    $log->set('field_action', $action_key);
    $log->set('field_to_status', $to_tid);
    $log->set('field_admin_override', $admin_override ? 1 : 0);
    $log->set('field_actor', $actor);

    // Optional fields.
    if ($log->hasField('field_from_status') && $from_tid > 0) {
      $log->set('field_from_status', $from_tid);
    }

    if ($context !== NULL && $context !== '' && $log->hasField('field_context')) {
      $log->set('field_context', $context);
    }

    try {
      $log->save();
      return TRUE;
    }
    catch (EntityStorageException $e) {
      // We do not block the action if logging fails; we simply report.
      \Drupal::messenger()->addError(t('Contract Action Log write failed: @msg', [
        '@msg' => $e->getMessage(),
      ]));
      return FALSE;
    }
  }

  /**
   * Write a log row for a generator/event outcome (per-section/per-WO).
   *
   * This uses the contract's CURRENT status as field_to_status to satisfy
   * required fields, without implying a status transition occurred.
   *
   * @param \Drupal\Core\Entity\EntityInterface $contract
   *   Contract entity.
   * @param string $event_key
   *   Machine key, e.g. wo_gen_created, wo_gen_skipped_allowlist, wo_gen_error_storage.
   * @param string $actor
   *   staff|client|system.
   * @param array $context
   *   JSON-serializable details (section_id, wo_id, bundle, reason, error, etc).
   * @param bool $admin_override
   *   TRUE if override context applies.
   *
   * @return bool
   *   TRUE if saved.
   */
  public static function writeEvent(
    EntityInterface $contract,
    string $event_key,
    string $actor = 'staff',
    array $context = [],
    bool $admin_override = FALSE
  ): bool {

    // Safety: only for contracts.
    if ($contract->getEntityTypeId() !== 'contracts') {
      return FALSE;
    }

    // Current status for required to_status.
    $to_tid = 0;
    if ($contract->hasField('field_contract_status') && !$contract->get('field_contract_status')->isEmpty()) {
      $to_tid = (int) $contract->get('field_contract_status')->target_id;
    }

    // Use same value for from_status to avoid implying a transition.
    $from_tid = $to_tid;

    // Encode context as JSON string for field_context.
    $context_json = '';
    if (!empty($context)) {
      $context_json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if ($context_json === FALSE) {
        $context_json = '';
      }
    }

    return self::write(
      $contract,
      $event_key,
      $from_tid,
      $to_tid,
      $admin_override,
      $actor,
      $context_json
    );
  }

}
