<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\contract_residential\ContractActionLogWriter;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Mark Contract as Completed for the Year (contract_status = 1127) with guardrails.
 * Admin override: role "administrator" may bypass guardrails.
 *
 * Guardrails:
 * - All "agreed" contract sections (field_do_you_want = 1 Yes or
 *   4 Accepted/Price Confirmed) must have at least one Work Order linked
 *   via field_work_order / field_2nd_work_order / field_3rd_work_order
 *   / field_4th_work_order, EXCEPT sections whose field_service term
 *   has field_on_demand = TRUE (route-based or condition-based — these
 *   may legitimately have zero WOs in a year).
 * - All linked Work Orders must be in a terminal status:
 *   1097 Complete, 1098 Canceled, 1281 Invoiced, 1283 Warrantied, 1504 Paid.
 * - At least one Work Order must exist across all qualifying sections.
 *
 * Transition rules:
 * - Allowed from: 1124 Work Orders Created, 1126 On Hold.
 * - Disallowed from: 1127 Completed (idempotent), 1128 Canceled.
 *
 * @Action(
 *   id = "contract_residential_mark_completed",
 *   label = @Translation("Mark Contract: Completed for the Year"),
 *   category = @Translation("Contracts"),
 *   confirm = TRUE
 * )
 */
class MarkContractCompletedAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * Terminal Work Order status TIDs that count as "done."
   */
  private const TERMINAL_WO_STATUSES = [
    1097, // Complete
    1098, // Canceled
    1281, // Invoiced
    1283, // Warrantied
    1504, // Paid
  ];

  /**
   * field_do_you_want values that count as agreed-to commitments.
   * Keys: '1' = Yes, '4' = Accepted / Price Confirmed.
   */
  private const AGREED_DO_YOU_WANT_VALUES = [
    '1', // Yes
    '4', // Accepted / Price Confirmed
  ];

  /**
   * Section fields holding Work Order references.
   */
  private const SECTION_WO_FIELDS = [
    'field_work_order',
    'field_2nd_work_order',
    'field_3rd_work_order',
    'field_4th_work_order',
  ];

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'contracts') {
      return;
    }

    if (!$entity->hasField('field_contract_status')) {
      $this->messenger()->addError($this->t('Contract @id is missing field_contract_status.', [
        '@id' => $entity->id(),
      ]));
      return;
    }

    $action_key = 'contract_residential_mark_completed';
    $target_tid = 1127;

    $current_tid = !$entity->get('field_contract_status')->isEmpty()
      ? (int) $entity->get('field_contract_status')->target_id
      : 0;

    if ($current_tid === $target_tid) {
      return;
    }

    $label = $entity->label() ?: $this->t('Contract @id', ['@id' => $entity->id()]);
    $is_admin_override = \Drupal::currentUser()->hasRole('administrator');
    $context = NULL;

    // Transition guardrail.
    if (!$is_admin_override) {
      $allowed_from = [
        1124, // Work Orders Created
        1126, // On Hold
      ];
      $disallowed_from = [
        1127, // Completed (idempotent above)
        1128, // Canceled
      ];

      if (in_array($current_tid, $disallowed_from, TRUE)) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Completed from a terminal status (TID: @current).',
          ['@label' => $label, '@current' => $current_tid]
        ));
        return;
      }

      if (!in_array($current_tid, $allowed_from, TRUE)) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Completed from current status (TID: @current). Allowed from: Work Orders Created (1124), On Hold (1126).',
          ['@label' => $label, '@current' => $current_tid ?: 'none']
        ));
        return;
      }
    }

    // Business guardrail — collect agreed sections and inspect WOs.
    $analysis = $this->analyzeContractCompletion($entity);

    // Failure: agreed, non-on-demand sections with no WO.
    if (!empty($analysis['sections_missing_wo'])) {
      $section_ids = implode(', ', $analysis['sections_missing_wo']);
      if (!$is_admin_override) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Completed: agreed sections have no Work Order linked. Section IDs: @ids.',
          ['@label' => $label, '@ids' => $section_ids]
        ));
        return;
      }
      $this->messenger()->addWarning($this->t(
        'Administrator override: @label completed despite sections without Work Orders. Section IDs: @ids.',
        ['@label' => $label, '@ids' => $section_ids]
      ));
      $context = 'admin_override_sections_missing_wo:' . $section_ids;
    }

    // Failure: WOs exist but some are not terminal.
    if (!empty($analysis['open_wo_ids'])) {
      $open_ids = implode(', ', $analysis['open_wo_ids']);
      if (!$is_admin_override) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Completed: open Work Orders exist. WO IDs: @ids.',
          ['@label' => $label, '@ids' => $open_ids]
        ));
        return;
      }
      $this->messenger()->addWarning($this->t(
        'Administrator override: @label completed despite open Work Orders. WO IDs: @ids.',
        ['@label' => $label, '@ids' => $open_ids]
      ));
      $context = ($context ? $context . ';' : '') . 'admin_override_open_wos:' . $open_ids;
    }

    // Failure: zero WOs across the contract entirely.
    if ($analysis['total_wos_seen'] === 0 && empty($analysis['sections_missing_wo'])) {
      if (!$is_admin_override) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Completed: no Work Orders exist for any agreed section.',
          ['@label' => $label]
        ));
        return;
      }
      $this->messenger()->addWarning($this->t(
        'Administrator override: @label completed with zero Work Orders.',
        ['@label' => $label]
      ));
      $context = ($context ? $context . ';' : '') . 'admin_override_no_wos';
    }

    $from_tid = $current_tid;

    $entity->set('field_contract_status', $target_tid);
    $entity->save();

    ContractActionLogWriter::write(
      $entity,
      $action_key,
      $from_tid,
      $target_tid,
      $is_admin_override,
      'staff',
      $context
    );

    if ($is_admin_override && $context) {
      return;
    }

    if ($is_admin_override) {
      $this->messenger()->addWarning($this->t('@label marked Completed for the Year (administrator).', [
        '@label' => $label,
      ]));
      return;
    }

    $this->messenger()->addMessage($this->t('@label marked Completed for the Year.', [
      '@label' => $label,
    ]));
  }

  /**
   * Analyze a contract's sections and Work Orders for completion readiness.
   */
  private function analyzeContractCompletion(EntityInterface $contract): array {
    $entity_type_manager = \Drupal::entityTypeManager();
    $section_storage = $entity_type_manager->getStorage('contract_sections');
    $wo_storage = $entity_type_manager->getStorage('work_order');

    $section_ids = $section_storage->getQuery()
      ->condition('field_contract', $contract->id())
      ->accessCheck(FALSE)
      ->execute();

    $sections_missing_wo = [];
    $collected_wo_ids = [];

    if (!empty($section_ids)) {
      $sections = $section_storage->loadMultiple($section_ids);

      foreach ($sections as $section) {
        if (!$section->hasField('field_do_you_want') || $section->get('field_do_you_want')->isEmpty()) {
          continue;
        }
        $do_you_want = (string) $section->get('field_do_you_want')->value;
        if (!in_array($do_you_want, self::AGREED_DO_YOU_WANT_VALUES, TRUE)) {
          continue;
        }

        // Skip on-demand services entirely.
        $is_on_demand = FALSE;
        if ($section->hasField('field_service') && !$section->get('field_service')->isEmpty()) {
          $service_term = $section->get('field_service')->entity;
          if ($service_term && $service_term->hasField('field_on_demand')
            && !$service_term->get('field_on_demand')->isEmpty()
            && (int) $service_term->get('field_on_demand')->value === 1) {
            $is_on_demand = TRUE;
          }
        }
        if ($is_on_demand) {
          continue;
        }

        // Collect WO references from this section.
        $section_wo_ids = [];
        foreach (self::SECTION_WO_FIELDS as $wo_field) {
          if (!$section->hasField($wo_field) || $section->get($wo_field)->isEmpty()) {
            continue;
          }
          foreach ($section->get($wo_field) as $item) {
            if (!empty($item->target_id)) {
              $section_wo_ids[] = (int) $item->target_id;
            }
          }
        }

        if (empty($section_wo_ids)) {
          $sections_missing_wo[] = (int) $section->id();
        }
        else {
          foreach ($section_wo_ids as $id) {
            $collected_wo_ids[$id] = $id;
          }
        }
      }
    }

    // Inspect WO statuses.
    $open_wo_ids = [];
    if (!empty($collected_wo_ids)) {
      $wos = $wo_storage->loadMultiple(array_values($collected_wo_ids));
      foreach ($wos as $wo) {
        if (!$wo->hasField('field_status') || $wo->get('field_status')->isEmpty()) {
          $open_wo_ids[] = (int) $wo->id();
          continue;
        }
        $status_tid = (int) $wo->get('field_status')->target_id;
        if (!in_array($status_tid, self::TERMINAL_WO_STATUSES, TRUE)) {
          $open_wo_ids[] = (int) $wo->id();
        }
      }
    }

    return [
      'sections_missing_wo' => $sections_missing_wo,
      'open_wo_ids' => $open_wo_ids,
      'total_wos_seen' => count($collected_wo_ids),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
