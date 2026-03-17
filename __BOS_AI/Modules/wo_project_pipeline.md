# BOS Module — wo_project_pipeline

Module: wo_project_pipeline

Purpose:
- Auto-create container Landscaping estimates from Estimate Requests
- Auto-create Work Orders from accepted component estimates
- Compute locate deadline and overdue fields on WO gate fields
- Provide VBO actions for the project pipeline view (/bos/projects)
- Provide the Add Components modal form for Landscaping container estimates

---

## Files

wo_project_pipeline/
  wo_project_pipeline.info.yml
  wo_project_pipeline.module
  wo_project_pipeline.routing.yml
  wo_project_pipeline.services.yml
  src/
    Service/
      WoProjectPipelineService.php
    Form/
      AddComponentsForm.php
    Plugin/Action/
      AssignSupervisorAction.php
      ChangeProjectStatusAction.php
      MarkCustomerNotifiedAction.php
      MarkLocateClearedAction.php
      MarkLocateRequestedAction.php
      MarkMaterialsStagedAction.php

---

## Service — WoProjectPipelineService

Registered as: wo_project_pipeline.pipeline_service

Dependencies: entity_type.manager, config_pages.loader, logger.factory

### createWorkOrderFromEstimate(EntityInterface $estimate): void

Entry point from hook_entity_insert/update on estimate entities.
Dispatches to bundle-specific methods.
Wrapped in try/catch — never throws, logs errors.

### createWoFromLandscaping(EntityInterface $container): void

Guards:
- field_is_container = TRUE
- field_mobil_deposit_received = TRUE
- Recursion guard: static $processing keyed by container ID

Behavior:
1. Load markup from business_setting.field_markup (default 1.30)
2. Load estimate_request for property/contact
3. Query children: field_parent_estimate = container ID
4. Per child without field_work_order:
   a. Create WO (bundle = child bundle, status TID 1503)
   b. field_service from child's field_estimate_type
   c. Transfer materials via transferMaterials()
   d. Write field_work_order to child, set child stage → 1418
5. Set container stage → 1418

### createWoFromSprinklerInstallation(EntityInterface $estimate): void

Guards:
- field_contract_signed = TRUE
- field_signing_deposit_received = TRUE
- field_work_order IS EMPTY
- Recursion guard: static $processing keyed by estimate ID

Behavior:
1. Load markup from business_setting
2. Load estimate_request for property/contact/service
3. Create WO (bundle: sprinkler_installation, status TID 1503)
4. Transfer materials
5. Set estimate stage → 1418, write field_work_order

### maybeCreateContainerEstimate(EntityInterface $estimate_request): void

Trigger: estimate_request INSERT only (not update)

Guards:
- bundle = standard
- Landscaping TID 364 in field_service
- PERMANENT recursion guard: static $processing['req_' . $id] — never unset

Behavior:
- estimate_intake runs first (hook priority)
- estimate_intake creates the landscaping estimate
- This method finds it and promotes it:
  - field_is_container = TRUE
  - field_estimate_type = 364
  - uid = current user
- Fallback: if no existing estimate, creates one from scratch

### transferMaterials(int $estimate_id, int $wo_id, string $wo_label, float $markup): void

Queries estimate_items WHERE type=materials AND field_estimate=$estimate_id
Creates wo_material_list (bundle: material_list) and wo_material_list_item per line:
- field_parts_used = material reference
- field_material_type = stocked_item
- field_material_cost = cost snapshot from material.field_cost_integer
- field_subtotal = cost × quantity
- field_subtotal_w_markup = subtotal × markup_multiplier

---

## Form — AddComponentsForm

Route: /bos/estimate/{estimate_id}/add-components
Access: authenticated users

Shows checklist from landscaping_component_references view.
Per component created:
- type: from service term field_service_bundle (NO fallback — skips if missing)
- field_parent_estimate: container ID (if field exists on bundle)
- field_stage: 1415 (In Preparation)
- field_scope_summary: "Client is requesting a Landscaping project with
  [Component Name] included. Please review and update."
- field_assigned_to: term's field_default_estimator → fallback container's
- field_is_container: FALSE
- field_is_current_revision: TRUE, field_revision_number: 1
- uid: current user

Already-added components shown as checked + disabled.
AJAX submit: CloseModalDialogCommand + RedirectCommand to /estimate/{id}

---

## Hooks in wo_project_pipeline.module

### hook_entity_presave (work_order)

Bundles: landscaping, sprinkler_installation

Computes:
- field_locate_deadline = field_locate_request_date + 3 business days
  (skips Saturday/Sunday, starts day after request date)
- field_locate_overdue = TRUE when deadline set AND not cleared AND today > deadline

### hook_entity_insert

- estimate (landscaping/sprinkler_installation): → createWorkOrderFromEstimate()
- estimate_request: → maybeCreateContainerEstimate()

### hook_entity_update

- estimate (landscaping/sprinkler_installation): → createWorkOrderFromEstimate()
- estimate_request: NOT called on update (insert-only for container creation)

---

## VBO Actions (for /bos/projects)

| Action ID | Behavior |
|---|---|
| assign_supervisor_action | Sets field_supervisor |
| change_project_status_action | Restricted TID list |
| mark_customer_notified_action | Sets field_customer_notified + date |
| mark_locate_cleared_action | Sets field_locate_cleared + date |
| mark_locate_requested_action | Sets field_locate_requested + date |
| mark_materials_staged_action | Sets field_materials_staged |

---

## Pre-Mobilization Gate Fields (WO landscaping + sprinkler_installation)

| Field | Type | Notes |
|---|---|---|
| field_locate_requested | boolean | Set via VBO |
| field_locate_request_date | date | Set via VBO |
| field_locate_deadline | date | Computed: +3 business days |
| field_locate_overdue | boolean | Computed: today > deadline AND not cleared |
| field_locate_cleared | boolean | Set via VBO |
| field_locate_clear_date | date | Set via VBO |
| field_customer_notified | boolean | Set via VBO |
| field_customer_notified_date | date | Set via VBO |
| field_materials_staged | boolean | Set via VBO |
| field_start_date | date | Planned start |
| field_estimated_duration_days | integer | From estimate |

---

## Pipeline View

Path: /bos/projects
Base entity: work_order
Bundles: landscaping + sprinkler_installation
Excludes: Complete (1097), Invoiced (1281), Paid (1504), Canceled (1098)

---

## Integration with estimate_intake

estimate_intake runs BEFORE wo_project_pipeline via hook_module_implements_alter.

Flow:
1. Estimate Request saved
2. estimate_intake: creates landscaping estimate, assigns estimator, sets title
3. wo_project_pipeline: finds new estimate, promotes to container

---

## Status

Created: March 2026 — Phase 1 Project Pipeline