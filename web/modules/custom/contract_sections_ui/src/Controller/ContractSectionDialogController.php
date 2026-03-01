<?php

namespace Drupal\contract_sections_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns a contract section edit form intended for modal display.
 *
 * UI-only: no data rules, no audit logic.
 */
final class ContractSectionDialogController extends ControllerBase {

  /**
   * Builds the modal edit form for a contract section.
   *
   * Route:
   *   /contracts/{contracts}/sections/{contract_sections}/edit-dialog
   */
  public function editDialog(
    EntityInterface $contracts,
    EntityInterface $contract_sections,
    Request $request
  ): array {

    // Ensure routing delivered the intended entity types.
    if ($contracts->getEntityTypeId() !== 'contracts') {
      throw new NotFoundHttpException('Invalid Contract entity.');
    }
    if ($contract_sections->getEntityTypeId() !== 'contract_sections') {
      throw new NotFoundHttpException('Invalid Contract Section entity.');
    }

    // Guardrail: ensure the section belongs to the contract in the URL.
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

    // Access check.
    if (!$contract_sections->access('update')) {
      throw new AccessDeniedHttpException();
    }

    // Note: we do NOT use destination for modal AJAX saves. No redirect.
    // But keeping a destination in the query won't break anything now.
    if (!$request->query->has('destination') || !$request->query->get('destination')) {
      $request->query->set(
        'destination',
        Url::fromRoute('entity.contracts.canonical', [
          'contracts' => $contracts->id(),
        ])->toString()
      );
    }

    // Build the edit form using the entity directly (ECK-safe).
    // If 'edit' is not a valid operation for your ECK entity, change to 'default'.
    $build = $this->entityFormBuilder()->getForm($contract_sections, 'edit');

    // Safety-net dialog library.
    $build['#attached']['library'][] = 'contract_sections_ui/dialog';
    $build['#attributes']['class'][] = 'contract-sections-ui-dialog-form';

    return $build;
  }

}
