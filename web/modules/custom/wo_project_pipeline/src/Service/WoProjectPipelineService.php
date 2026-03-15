<?php

declare(strict_types=1);

namespace Drupal\wo_project_pipeline\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\config_pages\ConfigPagesLoaderServiceInterface;

/**
 * Creates work orders from accepted estimates (landscaping, sprinkler_installation).
 * Auto-creates container estimates from estimate requests.
 */
class WoProjectPipelineService {

  /**
   * Recursion guard: estimate IDs currently being processed.
   *
   * @var array
   */
  protected static array $processing = [];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigPagesLoaderServiceInterface $configPagesLoader,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Creates work order(s) from an accepted estimate.
   *
   * Landscaping: container estimate → one WO per child component estimate.
   * Sprinkler installation: single estimate → single WO.
   */
  public function createWorkOrderFromEstimate(EntityInterface $estimate): void {
    $estimate_id = (int) $estimate->id();

    // Recursion guard.
    if (isset(self::$processing[$estimate_id])) {
      return;
    }

    try {
      $bundle = $estimate->bundle();

      if ($bundle === 'landscaping') {
        $this->createWoFromLandscaping($estimate);
      }
      elseif ($bundle === 'sprinkler_installation') {
        $this->createWoFromSprinklerInstallation($estimate);
      }
    }
    catch (\Throwable $e) {
      $this->logger()->error('Failed to create WO from estimate @id: @message', [
        '@id' => $estimate_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Landscaping pipeline: container estimate → one WO per child component.
   */
  protected function createWoFromLandscaping(EntityInterface $container): void {
    $container_id = (int) $container->id();

    // Must be a container estimate.
    if (empty($container->get('field_is_container')->value)) {
      return;
    }

    // Mobilization deposit must be received.
    if (empty($container->get('field_mobil_deposit_received')->value)) {
      return;
    }

    self::$processing[$container_id] = TRUE;

    try {
      // Load business setting markup multiplier.
      $markup_multiplier = 1.30;
      $business_settings = $this->configPagesLoader->load('business_setting');
      if ($business_settings && !$business_settings->get('field_markup')->isEmpty()) {
        $markup_multiplier = (float) $business_settings->get('field_markup')->value;
      }

      // Load estimate_request for property/contact/service.
      $estimate_request = NULL;
      if (!$container->get('field_estimate_request')->isEmpty()) {
        $estimate_request = $this->entityTypeManager
          ->getStorage('estimate_request')
          ->load($container->get('field_estimate_request')->target_id);
      }

      if (!$estimate_request) {
        $this->logger()->warning('Container estimate @id has no estimate_request — cannot create WOs.', [
          '@id' => $container_id,
        ]);
        return;
      }

      // Find child component estimates.
      $child_ids = $this->entityTypeManager
        ->getStorage('estimate')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'landscaping')
        ->condition('field_parent_estimate', $container_id)
        ->execute();

      if (empty($child_ids)) {
        $this->logger()->warning('Container estimate @id has no child components — cannot create WOs.', [
          '@id' => $container_id,
        ]);
        return;
      }

      $children = $this->entityTypeManager
        ->getStorage('estimate')
        ->loadMultiple($child_ids);

      $wo_storage = $this->entityTypeManager->getStorage('work_order');
      $wo_count = 0;

      foreach ($children as $child) {
        // Skip if this child already has a linked WO (duplicate guard per component).
        if (!$child->get('field_work_order')->isEmpty()) {
          continue;
        }

        $wo_values = [
          'type' => 'landscaping',
          'title' => 'WO - ' . $child->label(),
          'field_status' => 1503,
          'field_estimate' => $child->id(),
          'field_estimated_price' => $child->get('field_estimate_total')->value ?? 0,
        ];

        // Copy property from estimate_request.
        if (!$estimate_request->get('field_property')->isEmpty()) {
          $wo_values['field_property'] = $estimate_request->get('field_property')->target_id;
        }

        // Copy contact from estimate_request.
        if (!$estimate_request->get('field_contact')->isEmpty()) {
          $wo_values['field_contact'] = $estimate_request->get('field_contact')->target_id;
        }

        // Set service from component's field_estimate_type (landscape component term).
        if ($child->hasField('field_estimate_type') && !$child->get('field_estimate_type')->isEmpty()) {
          $wo_values['field_service'] = $child->get('field_estimate_type')->target_id;
        }
        elseif (!$estimate_request->get('field_service')->isEmpty()) {
          $wo_values['field_service'] = $estimate_request->get('field_service')->target_id;
        }

        // Copy scope summary if available.
        if ($child->hasField('field_scope_summary') && !$child->get('field_scope_summary')->isEmpty()) {
          $wo_values['field_work_todo_description'] = $child->get('field_scope_summary')->value;
        }

        // Copy estimated duration if available.
        if ($child->hasField('field_estimated_duration_days') && !$child->get('field_estimated_duration_days')->isEmpty()) {
          $wo_values['field_estimated_duration_days'] = $child->get('field_estimated_duration_days')->value;
        }

        $wo = $wo_storage->create($wo_values);
        $wo->save();
        $wo_id = (int) $wo->id();

        // Transfer materials from this child's estimate_items.
        $this->transferMaterials((int) $child->id(), $wo_id, $wo->label(), $markup_multiplier);

        // Write back linked WO to the child component estimate.
        self::$processing[(int) $child->id()] = TRUE;
        $child->set('field_work_order', $wo_id);
        $child->set('field_stage', 1418);
        $child->save();
        unset(self::$processing[(int) $child->id()]);

        $wo_count++;
      }

      // Set container estimate stage to Accepted.
      $container->set('field_stage', 1418);
      $container->save();

      $this->logger()->notice('@count WOs created from container estimate @id.', [
        '@count' => $wo_count,
        '@id' => $container_id,
      ]);
    }
    finally {
      unset(self::$processing[$container_id]);
    }
  }

  /**
   * Sprinkler installation pipeline: single estimate → single WO.
   */
  protected function createWoFromSprinklerInstallation(EntityInterface $estimate): void {
    $estimate_id = (int) $estimate->id();

    // Contract signed guard.
    if (empty($estimate->get('field_contract_signed')->value)) {
      return;
    }

    // Signing deposit received guard.
    if (empty($estimate->get('field_signing_deposit_received')->value)) {
      return;
    }

    // Duplicate guard — already has a linked WO.
    if (!$estimate->get('field_work_order')->isEmpty()) {
      return;
    }

    self::$processing[$estimate_id] = TRUE;

    try {
      // Load business setting markup multiplier.
      $markup_multiplier = 1.30;
      $business_settings = $this->configPagesLoader->load('business_setting');
      if ($business_settings && !$business_settings->get('field_markup')->isEmpty()) {
        $markup_multiplier = (float) $business_settings->get('field_markup')->value;
      }

      // Load estimate_request for property/contact/service.
      $estimate_request = NULL;
      if ($estimate->hasField('field_estimate_request') && !$estimate->get('field_estimate_request')->isEmpty()) {
        $estimate_request = $this->entityTypeManager
          ->getStorage('estimate_request')
          ->load($estimate->get('field_estimate_request')->target_id);
      }

      if (!$estimate_request) {
        $this->logger()->warning('Estimate @id has no estimate_request — cannot create WO.', [
          '@id' => $estimate_id,
        ]);
        return;
      }

      // Create work order.
      $wo_storage = $this->entityTypeManager->getStorage('work_order');
      $wo_values = [
        'type' => 'sprinkler_installation',
        'title' => 'WO - ' . $estimate->label(),
        'field_status' => 1503,
        'field_estimate' => $estimate_id,
        'field_estimated_price' => $estimate->get('field_estimate_total')->value ?? 0,
      ];

      // Copy property from estimate_request.
      if (!$estimate_request->get('field_property')->isEmpty()) {
        $wo_values['field_property'] = $estimate_request->get('field_property')->target_id;
      }

      // Copy contact from estimate_request.
      if (!$estimate_request->get('field_contact')->isEmpty()) {
        $wo_values['field_contact'] = $estimate_request->get('field_contact')->target_id;
      }

      // Copy service from estimate_request.
      if (!$estimate_request->get('field_service')->isEmpty()) {
        $wo_values['field_service'] = $estimate_request->get('field_service')->target_id;
      }

      // Copy scope summary if available.
      if ($estimate->hasField('field_scope_summary') && !$estimate->get('field_scope_summary')->isEmpty()) {
        $wo_values['field_work_todo_description'] = $estimate->get('field_scope_summary')->value;
      }

      // Copy estimated duration if available.
      if ($estimate->hasField('field_estimated_duration_days') && !$estimate->get('field_estimated_duration_days')->isEmpty()) {
        $wo_values['field_estimated_duration_days'] = $estimate->get('field_estimated_duration_days')->value;
      }

      $wo = $wo_storage->create($wo_values);
      $wo->save();

      $wo_id = (int) $wo->id();

      // Transfer materials from estimate_items.
      $this->transferMaterials($estimate_id, $wo_id, $wo->label(), $markup_multiplier);

      // Set estimate stage to Accepted and write back linked WO.
      $estimate->set('field_stage', 1418);
      $estimate->set('field_work_order', $wo_id);
      $estimate->save();

      $this->logger()->notice('Work order @wo_id created from estimate @est_id.', [
        '@wo_id' => $wo_id,
        '@est_id' => $estimate_id,
      ]);
    }
    finally {
      unset(self::$processing[$estimate_id]);
    }
  }

  /**
   * Auto-creates a container Landscaping estimate when an estimate_request
   * includes Landscaping (TID 364) in field_service.
   */
  public function maybeCreateContainerEstimate(EntityInterface $estimate_request): void {
    // Guard: only standard bundle.
    if ($estimate_request->bundle() !== 'standard') {
      return;
    }

    // Recursion guard.
    $request_id = (int) $estimate_request->id();
    if (isset(self::$processing['req_' . $request_id])) {
      return;
    }
    self::$processing['req_' . $request_id] = TRUE;

    try {
      // Guard: must have Landscaping (TID 364) in field_service.
      $services = $estimate_request->get('field_service')->getValue();
      $service_tids = array_column($services, 'target_id');
      if (!in_array(364, array_map('intval', $service_tids), TRUE)) {
        return;
      }

      // Guard: no container estimate already exists for this request.
      $existing = $this->entityTypeManager
        ->getStorage('estimate')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_estimate_request', $estimate_request->id())
        ->condition('type', 'landscaping')
        ->condition('field_is_container', 1)
        ->execute();
      if (!empty($existing)) {
        return;
      }

      // Load assigned_to from estimate_request.
      $assigned_to = NULL;
      if (!$estimate_request->get('field_assigned_to')->isEmpty()) {
        $assigned_to = $estimate_request->get('field_assigned_to')->target_id;
      }

      // Create container estimate.
      $estimate_values = [
        'type' => 'landscaping',
        'title' => 'Landscaping — ER#' . $estimate_request->id(),
        'field_estimate_request' => $estimate_request->id(),
        'field_is_container' => TRUE,
        'field_stage' => 1412,
      ];

      if ($assigned_to) {
        $estimate_values['field_assigned_to'] = $assigned_to;
      }

      $estimate = $this->entityTypeManager
        ->getStorage('estimate')
        ->create($estimate_values);
      $estimate->save();

      // Write back to estimate_request field_estimates.
      $estimate_request->get('field_estimates')->appendItem(['target_id' => $estimate->id()]);
      $estimate_request->save();

      $this->logger()->notice(
        'Container estimate @est_id created from estimate_request @req_id.',
        ['@est_id' => $estimate->id(), '@req_id' => $estimate_request->id()]
      );
    }
    catch (\Throwable $e) {
      $this->logger()->error('Failed to create container estimate from request @id: @message', [
        '@id' => $estimate_request->id(),
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      unset(self::$processing['req_' . $request_id]);
    }
  }

  /**
   * Transfers estimate_items:materials to wo_material_list + wo_material_list_item.
   */
  protected function transferMaterials(int $estimate_id, int $wo_id, string $wo_label, float $markup_multiplier): void {
    $items_storage = $this->entityTypeManager->getStorage('estimate_items');
    $ids = $items_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_estimate', $estimate_id)
      ->condition('type', 'materials')
      ->execute();

    if (empty($ids)) {
      return;
    }

    // Create the material list container.
    $list_storage = $this->entityTypeManager->getStorage('wo_material_list');
    $list = $list_storage->create([
      'type' => 'material_list',
      'title' => 'Materials - ' . $wo_label,
      'field_work_order' => $wo_id,
    ]);
    $list->save();
    $list_id = (int) $list->id();

    // Create line items.
    $item_storage = $this->entityTypeManager->getStorage('wo_material_list_item');
    $material_storage = $this->entityTypeManager->getStorage('material');
    $items = $items_storage->loadMultiple($ids);

    foreach ($items as $est_item) {
      $quantity = (int) ($est_item->get('field_quantity')->value ?? 0);
      if ($quantity <= 0) {
        continue;
      }

      // Get material reference and cost snapshot.
      $material_id = NULL;
      $cost = 0.0;
      if ($est_item->hasField('field_material') && !$est_item->get('field_material')->isEmpty()) {
        $material_id = (int) $est_item->get('field_material')->target_id;
        $material = $material_storage->load($material_id);
        if ($material && $material->hasField('field_cost_integer') && !$material->get('field_cost_integer')->isEmpty()) {
          $cost = (float) $material->get('field_cost_integer')->value;
        }
      }

      $subtotal = $cost * $quantity;
      $subtotal_w_markup = $subtotal * $markup_multiplier;

      $wo_item_values = [
        'type' => 'items',
        'field_list_id' => $list_id,
        'field_material_type' => 'stocked_item',
        'field_quantity' => $quantity,
        'field_material_cost' => $cost,
        'field_subtotal' => $subtotal,
        'field_subtotal_w_markup' => $subtotal_w_markup,
      ];

      if ($material_id) {
        $wo_item_values['field_parts_used'] = $material_id;
      }

      $wo_item = $item_storage->create($wo_item_values);
      $wo_item->save();
    }
  }

  /**
   * Returns the logger channel.
   */
  protected function logger() {
    return $this->loggerFactory->get('wo_project_pipeline');
  }

}
