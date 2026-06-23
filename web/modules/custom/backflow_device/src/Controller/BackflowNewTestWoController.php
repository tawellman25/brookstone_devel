<?php

namespace Drupal\backflow_device\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Creates a device-linked backflow testing work order from the device page.
 *
 * Scaffold only: a new work_order:backflow_testing for the device's property
 * (status Open) plus an empty wo_tasks_list:backflow_testing child already
 * linked to the device. No readings, scheduling, or fabricated data. The user
 * lands on the new work order's display page to continue the normal flow.
 */
class BackflowNewTestWoController extends ControllerBase {

  /**
   * Work order status taxonomy term ID for "Open".
   */
  const WO_STATUS_OPEN = 1089;

  /**
   * Create the WO + linked test child, then redirect to the WO.
   *
   * @param \Drupal\Core\Entity\EntityInterface $property_backflow_device
   *   The backflow device (param-converted from the route).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the new WO display page, or back to the device page on a
   *   missing-property guard (in which case nothing is created).
   */
  public function createWo(EntityInterface $property_backflow_device): RedirectResponse {
    $device = $property_backflow_device;

    // (a) Orphan guard: a device with no property cannot anchor a WO. Create
    // nothing and send the user back to the device page with an error.
    if ($device->get('field_property')->isEmpty()) {
      $this->messenger()->addError($this->t('This backflow device has no property set, so a test work order cannot be created. Set the device’s property first.'));
      return $this->redirect('entity.property_backflow_device.canonical', ['property_backflow_device' => $device->id()]);
    }
    $property_id = $device->get('field_property')->target_id;

    // Resolve the Backflow Testing service term from the Services taxonomy by
    // its bundle mapping (field_service_bundle), so it stays correct across
    // environments rather than hardcoding a term id.
    $service_tid = NULL;
    $service_ids = $this->entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'services')
      ->condition('field_service_bundle', 'backflow_testing')
      ->range(0, 1)
      ->execute();
    if ($service_ids) {
      $service_tid = reset($service_ids);
    }

    // (b) Create the work order. Status set directly to Open; scheduling fields
    // left empty so it flows through normal scheduling later.
    $wo_values = [
      'type' => 'backflow_testing',
      'field_property' => $property_id,
      'field_status' => self::WO_STATUS_OPEN,
    ];
    if ($service_tid) {
      $wo_values['field_service'] = $service_tid;
    }
    $wo = $this->entityTypeManager()->getStorage('work_order')->create($wo_values);
    $wo->save();

    // (c) Create the device-linked test child (scaffold; no readings).
    $child = NULL;
    try {
      $child = $this->entityTypeManager()->getStorage('wo_tasks_list')->create([
        'type' => 'backflow_testing',
        'field_work_order' => $wo->id(),
        'field_backflow_device' => $device->id(),
      ]);
      $child->save();
    }
    catch (\Exception $e) {
      $child = NULL;
      $this->getLogger('backflow_device')->error('New-test-WO: work order @wo created, but the linked test child failed to save: @msg', [
        '@wo' => $wo->id(),
        '@msg' => $e->getMessage(),
      ]);
    }

    // (d) Land the user on the test child's edit form — that form IS the test
    // readings form (wo_backflow_testing_form_alter builds the device + readings
    // UI on it). Destination returns to the new WO after the test is saved, so
    // the WO is the anchor. The device + parent WO are already set, so the user
    // just enters readings.
    if ($child && !$child->isNew()) {
      $this->messenger()->addStatus($this->t('Backflow test work order created. Enter the test results below.'));
      return $this->redirect('entity.wo_tasks_list.edit_form',
        ['wo_tasks_list' => $child->id()],
        ['query' => ['destination' => $wo->toUrl()->toString()]]
      );
    }

    // (e) Child could not be created — keep the valid WO and land there; the
    // test can be added later from the work order.
    $this->messenger()->addWarning($this->t('Work order created, but the linked test record could not be added automatically. You can add it from the work order.'));
    return $this->redirect('entity.work_order.canonical', ['work_order' => $wo->id()]);
  }

  /**
   * Add ANOTHER backflow test to an existing WO, then open its readings form.
   *
   * Used by the "Enter Test Results" button on the WO page so a device can have
   * more than one test (e.g. before- and after-repair). The new test inherits
   * the device from the WO's most recent existing test (the same-device case),
   * leaving it editable on the form.
   *
   * @param \Drupal\Core\Entity\EntityInterface $work_order
   *   The backflow_testing work order (param-converted from the route).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the new test child's edit form (destination back to the WO).
   */
  public function addTest(EntityInterface $work_order): RedirectResponse {
    $tl = $this->entityTypeManager()->getStorage('wo_tasks_list');

    // Inherit the device from the WO's most recent existing test, if any.
    $device_id = NULL;
    $existing = $tl->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'backflow_testing')
      ->condition('field_work_order', $work_order->id())
      ->sort('id', 'DESC')
      ->execute();
    foreach ($existing as $eid) {
      $prior = $tl->load($eid);
      if ($prior && !$prior->get('field_backflow_device')->isEmpty()) {
        $device_id = $prior->get('field_backflow_device')->target_id;
        break;
      }
    }

    $values = [
      'type' => 'backflow_testing',
      'field_work_order' => $work_order->id(),
    ];
    if ($device_id) {
      $values['field_backflow_device'] = $device_id;
    }
    $child = $tl->create($values);
    $child->save();

    $this->messenger()->addStatus($this->t('Added a backflow test. Enter the test results below.'));
    return $this->redirect('entity.wo_tasks_list.edit_form',
      ['wo_tasks_list' => $child->id()],
      ['query' => ['destination' => $work_order->toUrl()->toString()]]
    );
  }

}
