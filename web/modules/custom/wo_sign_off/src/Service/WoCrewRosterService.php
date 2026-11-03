<?php

declare(strict_types=1);

namespace Drupal\wo_sign_off\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Single source of truth for "who was on this WO" by sign-off context.
 *
 * Phase 2 sign-off reconciliation needs to identify the crew roster for
 * a work order so it can verify that every roster member has a complete
 * wo_time_clock entry before the sign-off save commits. The roster
 * source differs by sign-off path:
 *
 *   - wo_complete_info × six bundles (complete, landscape_crew,
 *     clean_up_crew, fertilizing_crew, irrigation_crew, spray_crew):
 *     roster comes from field_those_on_crew on the wo_complete_info
 *     entity itself. snow_removal is intentionally NOT in the list
 *     even though the field exists there — deferred to fall 2026.
 *
 *   - wo_tasks_list × lawn_mowing bundle: roster comes from
 *     field_mowing_who_on_site. special_mowing is intentionally NOT in
 *     the list — deferred per Phase 2 diagnostic (8 entries; structural
 *     divergence from wo_lawn_mowing's cascade pattern).
 *
 *   - Anything else: out of scope, returns empty array.
 *
 * Two read methods are exposed:
 *
 *   - getCrewForWorkOrder(): reads from a saved sign-off entity by
 *     looking it up via field_work_order. Returns an empty array if
 *     no such entity exists yet (e.g., the form is for a new entity
 *     that hasn't been saved).
 *
 *   - normalizeRosterFromFormState(): reads from the in-flight form
 *     state values, which is what Phase 2's form alter / validate /
 *     submit handlers use. Form state reflects the current user-edited
 *     values including AJAX updates, so it's the right source for
 *     pre-save reconciliation.
 *
 * Both methods return a deduplicated array of integer user IDs.
 */
final class WoCrewRosterService {

  /**
   * The six wo_complete_info bundles Phase 2 reconciliation covers.
   * snow_removal is deliberately excluded.
   */
  public const COMPLETE_INFO_BUNDLES = [
    'complete',
    'landscape_crew',
    'clean_up_crew',
    'fertilizing_crew',
    'irrigation_crew',
    'spray_crew',
    'lighting_crew',
  ];

  /**
   * The wo_tasks_list bundles Phase 2 reconciliation covers.
   * special_mowing is deliberately excluded.
   */
  public const TASKS_LIST_BUNDLES = ['lawn_mowing'];

  /**
   * Bundles where missing-entry creation needs explicit per-person
   * start/end times (multi-cycle work — sprinkler, landscape — where
   * teammates work different windows on the same WO and a single
   * default for the WO would be wildly wrong for half the crew).
   *
   * All other in-scope bundles silent-create missing entries with a
   * derived default (earliest existing entry on the WO, or WO created
   * timestamp). Orphan close-out is universal across all bundles
   * regardless of complexity.
   *
   * Currently only applies to wo_complete_info bundles; there is no
   * "complex" wo_tasks_list bundle in scope.
   */
  public const COMPLEX_BUNDLES = [
    'landscape_crew',
    'irrigation_crew',
    'lighting_crew',
  ];

  /**
   * Whether the bundle requires per-row UI for missing entries.
   *
   * @see self::COMPLEX_BUNDLES
   */
  public function isComplexBundle(string $signoff_entity_type, string $signoff_bundle): bool {
    return $signoff_entity_type === 'wo_complete_info'
      && in_array($signoff_bundle, self::COMPLEX_BUNDLES, TRUE);
  }

  /**
   * Whether this WO has a clock-in the simple path can anchor to.
   *
   * The simple path's whole premise is "one crew, one shift": a missing
   * teammate inherits the start time of whoever DID clock in, so the crew's
   * day lines up. That only works if somebody clocked in.
   *
   * @see self::requiresManualTimes()
   */
  public function hasAnchorClockIn(int $wo_id): bool {
    if ($wo_id <= 0) {
      return FALSE;
    }
    $ids = $this->entityTypeManager->getStorage('wo_time_clock')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_work_order', $wo_id)
      ->exists('field_start_time')
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }

  /**
   * Whether missing entries must be typed in rather than derived.
   *
   * TRUE for the complex bundles (teammates work different windows, so no
   * single WO-wide default fits), and ALSO whenever the work order has no
   * clock-in at all to anchor to.
   *
   * ⚠ That second condition is the 2026-11-02 fix. Before it, a WO with zero
   * clock-ins fell through to "start = the WO's created timestamp, end = now".
   * That was a fair approximation of the work day back when a WO was opened
   * the morning it was worked. Seasonal work orders are created in batches
   * weeks ahead: WO 54225 (fall_cleanup, 1501 East 3rd St.) was created 10/07
   * and signed off 11/02, so the derived entry came to **623 hours** for each
   * of four crew. The entry-hours guard rejected it, which is the system
   * working — but the sign-off then failed outright and nobody's time was
   * recorded. 315 open work orders had no clock-ins and were sitting on the
   * same trap.
   *
   * So: when there is nothing to derive a start from, do not invent one. Ask.
   */
  public function requiresManualTimes(string $signoff_entity_type, string $signoff_bundle, int $wo_id): bool {
    return $this->isComplexBundle($signoff_entity_type, $signoff_bundle)
      || !$this->hasAnchorClockIn($wo_id);
  }

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Read the crew roster for a WO by looking up the saved sign-off entity.
   *
   * @param int $wo_id
   *   Work order entity ID.
   * @param string $signoff_entity_type
   *   Either 'wo_complete_info' or 'wo_tasks_list'.
   * @param string $signoff_bundle
   *   The bundle of the sign-off entity. Must be in the appropriate
   *   bundle list for the entity type.
   *
   * @return int[]
   *   Deduplicated user IDs. Empty when out of scope, no sign-off
   *   entity exists for this WO yet, or the roster field is empty.
   */
  public function getCrewForWorkOrder(int $wo_id, string $signoff_entity_type, string $signoff_bundle): array {
    if (!$this->isInScope($signoff_entity_type, $signoff_bundle)) {
      return [];
    }
    if ($wo_id <= 0) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage($signoff_entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $signoff_bundle)
      ->condition('field_work_order', $wo_id)
      ->range(0, 1)
      ->execute();
    if (empty($ids)) {
      return [];
    }

    $entity = $storage->load(reset($ids));
    if (!$entity) {
      return [];
    }

    $field_name = $this->rosterFieldFor($signoff_entity_type);
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return [];
    }

    $uids = [];
    foreach ($entity->get($field_name) as $item) {
      if (!empty($item->target_id)) {
        $uids[] = (int) $item->target_id;
      }
    }
    return array_values(array_unique($uids));
  }

  /**
   * Read the crew roster from in-flight form state values.
   *
   * Use during form lifecycle (alter, validate, submit) where the
   * user-edited roster may differ from what's persisted on the entity.
   *
   * @param array $form_state_values
   *   The full form state values array. Typically passed as
   *   $form_state->getValues().
   * @param string $signoff_entity_type
   *   Either 'wo_complete_info' or 'wo_tasks_list'.
   * @param string $signoff_bundle
   *   The bundle of the sign-off entity.
   *
   * @return int[]
   *   Deduplicated user IDs. Empty when out of scope or the relevant
   *   roster field has no values in the submitted form state.
   */
  public function normalizeRosterFromFormState(array $form_state_values, string $signoff_entity_type, string $signoff_bundle): array {
    if (!$this->isInScope($signoff_entity_type, $signoff_bundle)) {
      return [];
    }

    $field_name = $this->rosterFieldFor($signoff_entity_type);
    if (!isset($form_state_values[$field_name])) {
      return [];
    }

    $uids = [];
    foreach ((array) $form_state_values[$field_name] as $item) {
      if (is_array($item) && !empty($item['target_id'])) {
        $uids[] = (int) $item['target_id'];
      }
      elseif (is_numeric($item)) {
        $uids[] = (int) $item;
      }
    }
    return array_values(array_unique($uids));
  }

  /**
   * Whether the given entity type / bundle is covered by Phase 2.
   *
   * Public so callers (form alter, presave guard) can short-circuit
   * cheaply without first attempting a load.
   */
  public function isInScope(string $signoff_entity_type, string $signoff_bundle): bool {
    return match ($signoff_entity_type) {
      'wo_complete_info' => in_array($signoff_bundle, self::COMPLETE_INFO_BUNDLES, TRUE),
      'wo_tasks_list' => in_array($signoff_bundle, self::TASKS_LIST_BUNDLES, TRUE),
      default => FALSE,
    };
  }

  /**
   * The roster field name for a given sign-off entity type.
   *
   * @return string
   *   Field machine name.
   */
  private function rosterFieldFor(string $signoff_entity_type): string {
    return match ($signoff_entity_type) {
      'wo_complete_info' => 'field_those_on_crew',
      'wo_tasks_list' => 'field_mowing_who_on_site',
    };
  }

}
