<?php

namespace Drupal\estimates\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for creating Landscaping estimates from a Work Order.
 */
class LandscapingEstimateController extends ControllerBase {

  /**
   * Creates a Landscaping estimate linked to the given Work Order and
   * redirects to its edit form.
   *
   * @param \Drupal\Core\Entity\EntityInterface $work_order
   *   The Work Order ECK entity from the route parameter.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the estimate edit form.
   */
  public function createFromWorkOrder(EntityInterface $work_order): RedirectResponse {
    if ($work_order->getEntityTypeId() !== 'work_order') {
      throw new \InvalidArgumentException('Route parameter "work_order" must be of type work_order.');
    }

    // Create the estimate entity.
    $storage = $this->entityTypeManager()->getStorage('estimate');

    /** @var \Drupal\Core\Entity\ContentEntityInterface $estimate */
    $estimate = $storage->create([
      'type' => 'landscaping',
    ]);

    // Link to Work Order.
    if ($estimate->hasField('field_work_order')) {
      $estimate->set('field_work_order', $work_order->id());
    }

    // Cross-reference property from WO if present.
    if ($estimate->hasField('field_property')
      && $work_order->hasField('field_property')
      && !$work_order->get('field_property')->isEmpty()
    ) {
      $estimate->set('field_property', $work_order->get('field_property')->target_id);
    }

    // Default: not a brand-new client when created from an existing WO.
    if ($estimate->hasField('field_new_client')) {
      $estimate->set('field_new_client', 0);
    }

    // Pre-assign estimator to current user if field exists.
    if ($estimate->hasField('field_assigned_to')) {
      $estimate->set('field_assigned_to', $this->currentUser()->id());
    }

    // Sync WO description → estimate client requested (so they match).
    if (
      $estimate->hasField('field_client_requested')
      && $work_order->hasField('field_work_todo_description')
      && !$work_order->get('field_work_todo_description')->isEmpty()
      && $estimate->get('field_client_requested')->isEmpty()
    ) {
      $estimate->set('field_client_requested', $work_order->get('field_work_todo_description')->value);
    }

    $estimate->save();

    // Redirect directly to this estimate's edit form route.
    return $this->redirect('entity.estimate.edit_form', [
      'estimate' => $estimate->id(),
    ]);
  }

}
