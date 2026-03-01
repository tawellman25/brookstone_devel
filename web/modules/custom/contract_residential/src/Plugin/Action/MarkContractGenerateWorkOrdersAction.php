<?php

declare(strict_types=1);

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\contract_residential\ContractActionLogWriter;
use Drupal\contract_residential\Service\WorkOrderGenerator;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generate Work Orders for a Residential Contract and promote status when complete.
 *
 * BOS rules:
 * - Allowed when Contract status is:
 *   - 1123 (Approved)
 *   - 1651 (Generate Work Orders / recovery)
 *   - 1124 (Work Orders Created = allowlist-complete-as-of-now; reruns allowed)
 * - Work Orders are created by the clicking user (never Anonymous).
 * - Status 1124 means: all eligible AUTO-GEN (allowlist) WOs are created & linked at this point in time.
 *
 * @Action(
 *   id = "contract_residential_mark_generate_work_orders",
 *   label = @Translation("Generate Work Orders"),
 *   type = "contracts"
 * )
 */
final class MarkContractGenerateWorkOrdersAction extends ActionBase implements ContainerFactoryPluginInterface {

  private const STATUS_TID_APPROVED = 1123;
  private const STATUS_TID_GENERATE_WO = 1651;
  private const STATUS_TID_WO_CREATED = 1124;

  /**
   * Messenger service (do NOT name this $messenger; PluginBase already uses that).
   */
  protected MessengerInterface $contractMessenger;

  protected WorkOrderGenerator $workOrderGenerator;

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MessengerInterface $contractMessenger,
    WorkOrderGenerator $workOrderGenerator,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->contractMessenger = $contractMessenger;
    $this->workOrderGenerator = $workOrderGenerator;
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('contract_residential.work_order_generator'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * IMPORTANT:
   * ActionInterface::access() does NOT typehint $object, so we must not either.
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();

    if (!$object instanceof EntityInterface) {
      $access = AccessResult::forbidden();
      return $return_as_object ? $access : $access->isAllowed();
    }

    // Must be authenticated (hard rule: never run as Anonymous).
    if ((int) $account->id() <= 0) {
      $access = AccessResult::forbidden('Anonymous users may not generate Work Orders.');
      return $return_as_object ? $access : $access->isAllowed();
    }

    // Must be a Residential Contract.
    if ($object->getEntityTypeId() !== 'contracts' || $object->bundle() !== 'residential') {
      $access = AccessResult::forbidden('Only applies to Residential Contracts.');
      return $return_as_object ? $access : $access->isAllowed();
    }

    // Must have status field and be in an eligible status.
    if (!$object->hasField('field_contract_status') || $object->get('field_contract_status')->isEmpty()) {
      $access = AccessResult::forbidden('Contract missing status.');
      return $return_as_object ? $access : $access->isAllowed();
    }

    $tid = (int) $object->get('field_contract_status')->target_id;

    // ✅ Allow reruns from 1124 (Work Orders Created).
    if (!in_array($tid, [self::STATUS_TID_APPROVED, self::STATUS_TID_GENERATE_WO, self::STATUS_TID_WO_CREATED], TRUE)) {
      $access = AccessResult::forbidden('Contract status not eligible for generation.');
      return $return_as_object ? $access : $access->isAllowed();
    }

    // Defer to entity update access; action should not bypass entity perms.
    $access = $object->access('update', $account, TRUE);

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL): void {
    if (!$entity instanceof EntityInterface) {
      return;
    }

    if ($entity->getEntityTypeId() !== 'contracts' || $entity->bundle() !== 'residential') {
      $this->contractMessenger->addError(t('This action only applies to Residential Contracts.'));
      return;
    }

    if (!$entity->hasField('field_contract_status') || $entity->get('field_contract_status')->isEmpty()) {
      $this->contractMessenger->addError(t('Contract is missing field_contract_status.'));
      return;
    }

    $actor_uid = (int) \Drupal::currentUser()->id();
    if ($actor_uid <= 0) {
      $this->contractMessenger->addError(t('REFUSED: Cannot generate Work Orders as Anonymous.'));
      return;
    }

    $cid = (int) $entity->id();
    $current_tid = (int) $entity->get('field_contract_status')->target_id;

    $action_key = (string) ($this->getPluginId() ?: 'contract_residential_mark_generate_work_orders');

    // Allowed statuses: 1123 (Approved), 1651 (Generate Work Orders), 1124 (Work Orders Created).
    if (!in_array($current_tid, [self::STATUS_TID_APPROVED, self::STATUS_TID_GENERATE_WO, self::STATUS_TID_WO_CREATED], TRUE)) {
      $this->contractMessenger->addError(t('REFUSED: Contract @cid is not eligible. Allowed only: 1123 (Approved), 1651 (Generate Work Orders), or 1124 (Work Orders Created).', [
        '@cid' => $cid,
      ]));
      return;
    }

    // If starting from Approved OR Work Orders Created, move into Generate Work Orders before running.
    if ($current_tid === self::STATUS_TID_APPROVED || $current_tid === self::STATUS_TID_WO_CREATED) {
      $from_tid = $current_tid;

      $entity->set('field_contract_status', self::STATUS_TID_GENERATE_WO);
      $entity->save();

      ContractActionLogWriter::write(
        $entity,
        $action_key,
        $from_tid,
        self::STATUS_TID_GENERATE_WO,
        FALSE,
        'staff',
        $from_tid === self::STATUS_TID_APPROVED
          ? 'Entered Generate Work Orders prior to auto-generation.'
          : 'Re-entered Generate Work Orders from Work Orders Created (1124) for rerun.'
      );
    }
    else {
      // Log attempt even when already in recovery.
      ContractActionLogWriter::write(
        $entity,
        $action_key,
        self::STATUS_TID_GENERATE_WO,
        self::STATUS_TID_GENERATE_WO,
        FALSE,
        'staff',
        'Re-run generation while already in Generate Work Orders (recovery/idempotent run).'
      );
    }

    // ===== LOOP UNTIL COMPLETE =====
    // Multi-stage sections may require multiple passes to fill 2nd/3rd/4th pointers.
    $max_passes = 5;
    $pass = 0;

    do {
      $pass++;

      // REAL generation pass.
      $result = $this->workOrderGenerator->generateFromContract($cid, [
        'dry_run' => FALSE,
        'fill_multistage_slots' => TRUE,
        'set_work_todo_description' => TRUE,
      ]);

      ContractActionLogWriter::write(
        $entity,
        $action_key,
        self::STATUS_TID_GENERATE_WO,
        self::STATUS_TID_GENERATE_WO,
        FALSE,
        'staff',
        sprintf(
          'Generation pass %d/%d: created=%d would=%d skipped=%d dry_run=0',
          $pass,
          $max_passes,
          $result->getCreated(),
          $result->getWouldCreate(),
          $result->getSkipped()
        )
      );

      if ($result->hasErrors()) {
        foreach ($result->getMessages() as $m) {
          $line = (string) $m;
          if (stripos($line, 'ERROR') !== FALSE || stripos($line, 'REFUSED') !== FALSE) {
            $this->contractMessenger->addError($line);
          }
        }
        $this->contractMessenger->addWarning(t('Generation reported errors. Contract remains in Generate Work Orders (1651).'));
        return;
      }

      // COMPLETION CHECK (dry-run).
      $check = $this->workOrderGenerator->generateFromContract($cid, [
        'dry_run' => TRUE,
        'fill_multistage_slots' => TRUE,
        'set_work_todo_description' => FALSE,
      ]);

      ContractActionLogWriter::write(
        $entity,
        $action_key,
        self::STATUS_TID_GENERATE_WO,
        self::STATUS_TID_GENERATE_WO,
        FALSE,
        'staff',
        sprintf(
          'Completion check after pass %d/%d: would=%d skipped=%d dry_run=1',
          $pass,
          $max_passes,
          $check->getWouldCreate(),
          $check->getSkipped()
        )
      );

      if ($check->hasErrors()) {
        foreach ($check->getMessages() as $m) {
          $line = (string) $m;
          if (stripos($line, 'ERROR') !== FALSE || stripos($line, 'REFUSED') !== FALSE) {
            $this->contractMessenger->addError($line);
          }
        }
        $this->contractMessenger->addWarning(t('Completion check reported errors. Contract remains in Generate Work Orders (1651).'));
        return;
      }

      // Done when nothing remains.
      if ($check->getWouldCreate() === 0) {
        break;
      }

    } while ($pass < $max_passes);

    // If we hit cap and still have remaining work, do not promote.
    if (isset($check) && $check->getWouldCreate() !== 0) {
      $this->contractMessenger->addWarning(t('Not promoted to Work Orders Created. Completion check would-create=@would after @passes pass(es). Contract remains in Generate Work Orders (1651).', [
        '@would' => $check->getWouldCreate(),
        '@passes' => $pass,
      ]));
      return;
    }

    // Promote to 1124.
    $fresh = $this->entityTypeManager->getStorage('contracts')->load($cid);
    if (!$fresh instanceof EntityInterface || !$fresh->hasField('field_contract_status')) {
      $this->contractMessenger->addError(t('Unable to reload Contract @cid for status update.', ['@cid' => $cid]));
      return;
    }

    $fresh->set('field_contract_status', self::STATUS_TID_WO_CREATED);
    $fresh->save();

    ContractActionLogWriter::write(
      $fresh,
      $action_key,
      self::STATUS_TID_GENERATE_WO,
      self::STATUS_TID_WO_CREATED,
      FALSE,
      'staff',
      sprintf('Promoted to Work Orders Created after %d pass(es).', $pass)
    );

    $this->contractMessenger->addStatus(t('Generation complete. Contract @cid promoted to Work Orders Created (1124).', [
      '@cid' => $cid,
    ]));
  }

}
