<?php

namespace Drupal\contract_sections_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\views\Views;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Modal dialog that shows audit history for a single contract section.
 */
final class ContractSectionHistoryDialogController extends ControllerBase {

  /**
   * Render audit history for a contract section.
   */
  public function historyDialog(EntityInterface $contracts, EntityInterface $contract_sections): array {
    if ($contracts->getEntityTypeId() !== 'contracts') {
      throw new NotFoundHttpException('Invalid Contract entity.');
    }
    if ($contract_sections->getEntityTypeId() !== 'contract_sections') {
      throw new NotFoundHttpException('Invalid Contract Section entity.');
    }

    // Guardrail: ensure section belongs to contract.
    if (
      !$contract_sections->hasField('field_contract') ||
      $contract_sections->get('field_contract')->isEmpty()
    ) {
      throw new NotFoundHttpException('Contract Section is missing field_contract.');
    }
    $parent = $contract_sections->get('field_contract')->entity;
    if (!$parent || (string) $parent->id() !== (string) $contracts->id()) {
      throw new NotFoundHttpException('Contract Section does not belong to this Contract.');
    }

    // Access: at minimum, if they can view the section, they can view its history.
    if (!$contract_sections->access('view')) {
      throw new AccessDeniedHttpException();
    }

    // Render the audit view display.
    // View ID: contract_sections_audit_log
    // Display ID: block_1  (you can swap this later to a dedicated "dialog" display)
    $view = Views::getView('contract_sections_audit_log');
    if (!$view) {
      throw new NotFoundHttpException('Audit view not found.');
    }

    $view->setDisplay('block_1');

    // IMPORTANT:
    // This assumes your audit view's contextual filter expects the contract_section ID.
    // If it expects a different argument (like contract ID), change this.
    $view->setArguments([(string) $contract_sections->id()]);

    $build = $view->render();

    // Ensure modal AJAX libs are available.
    $build['#attached']['library'][] = 'contract_sections_ui/dialog';

    return $build;
  }

}
