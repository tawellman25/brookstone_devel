<?php

namespace Drupal\contract_board\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contract Board pipeline dashboard controller.
 */
class ContractBoardController extends ControllerBase {

  protected Connection $database;
  protected DateFormatterInterface $dateFormatter;

  /**
   * Active pipeline statuses in display order.
   * Excluded: 1120 Client Viewed, 1125 Assigned, 1126 On Hold,
   *           1127 Completed, 1128 Canceled.
   */
  const PIPELINE_ORDER = [
    1117 => 'Created – Updating',
    1118 => 'Ready to Send',
    1119 => 'Sent / Posted',
    1121 => 'Received Back',
    1122 => 'Changes Entered',
    1123 => 'Approved',
    1124 => 'Work Orders Created',
  ];

  /**
   * Map: current status TID → action plugin ID for the Next button.
   * Approved (1123) is deliberately absent — no Next button.
   */
  const NEXT_ACTION_MAP = [
    1117 => 'contract_residential_mark_ready_to_send',
    1118 => 'contract_residential_mark_sent_posted',
    1119 => 'contract_residential_mark_received_back',
    1121 => 'contract_residential_mark_changes_entered',
    1122 => 'contract_residential_mark_approved',
    1124 => 'contract_residential_mark_completed',
  ];

  /**
   * Map: current status TID → next status label for the button text.
   */
  const NEXT_LABEL_MAP = [
    1117 => 'Ready to Send',
    1118 => 'Sent / Posted',
    1119 => 'Received Back',
    1121 => 'Changes Entered',
    1122 => 'Approved',
    1124 => 'Completed',
  ];

  /**
   * Follow-up thresholds per status (days without activity).
   */
  const FOLLOWUP_THRESHOLDS = [
    1118 => 3,  // Ready to Send
    1119 => 7,  // Sent / Posted
    1121 => 5,  // Received Back
    1123 => 5,  // Approved
  ];

  public function __construct(Connection $database, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * Renders the Contract Board dashboard.
   */
  public function board(): array {
    $year = (int) date('Y');
    $pipeline = $this->getPipeline($year);
    return [
      '#theme' => 'contract_board',
      '#followups' => $this->getFollowUps($year),
      '#pipeline' => $pipeline,
      '#year' => $year,
      '#csrf_token' => \Drupal::csrfToken()->get('contract_board_status_update'),
      '#attached' => [
        'library' => ['contract_board/contract_board'],
        'drupalSettings' => [
          'contractBoard' => [
            'csrfToken' => \Drupal::csrfToken()->get('contract_board_status_update'),
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Returns pipeline swimlane data for the board.
   */
  protected function getPipeline(int $year): array {
    $pipeline = [];
    $order_keys = array_keys(self::PIPELINE_ORDER);
    $now_ts = \Drupal::time()->getRequestTime();

    foreach (self::PIPELINE_ORDER as $tid => $label) {
      $query = $this->database->select('contracts_field_data', 'c');
      $query->fields('c', ['id', 'title', 'created']);
      $query->condition('c.type', 'residential');

      $query->join('contracts__field_contract_status', 'cs', 'cs.entity_id = c.id AND cs.deleted = 0');
      $query->condition('cs.field_contract_status_target_id', $tid);

      $query->join('contracts__field_contract_year', 'cy', 'cy.entity_id = c.id AND cy.deleted = 0');
      $query->condition('cy.field_contract_year_value', $year);

      // Property owner.
      $query->leftJoin('contracts__field_property_owner', 'cpo', 'cpo.entity_id = c.id AND cpo.deleted = 0');
      $query->leftJoin('users_field_data', 'ou', 'ou.uid = cpo.field_property_owner_target_id');
      $query->addField('ou', 'name', 'owner_name');

      // Property.
      $query->leftJoin('contracts__field_property', 'cp', 'cp.entity_id = c.id AND cp.deleted = 0');
      $query->leftJoin('properties_field_data', 'pfd', 'pfd.id = cp.field_property_target_id');
      $query->addField('pfd', 'title', 'property_title');

      $query->orderBy('c.changed', 'DESC');
      $results = $query->execute()->fetchAll();

      $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
      $slug = trim($slug, '-');

      $next_action = self::NEXT_ACTION_MAP[$tid] ?? NULL;
      $next_label = self::NEXT_LABEL_MAP[$tid] ?? NULL;

      $requests = [];
      foreach ($results as $row) {
        $cid = (int) $row->id;

        try {
          $url = Url::fromRoute('entity.contracts.canonical', ['contracts' => $cid])->toString();
        }
        catch (\Exception $e) {
          $url = '/contracts/' . $cid;
        }

        $client = trim($row->owner_name ?? '') ?: 'Unknown';
        $property = trim($row->property_title ?? '') ?: '';
        $age_days = $this->getContractAgeDays($cid, (int) $row->created);
        $services = $this->getContractServices($cid);

        $requests[] = [
          'id' => $cid,
          'client_name' => $client,
          'property' => $property,
          'services' => $services,
          'year' => $year,
          'age_days' => $age_days,
          'url' => $url,
          'next_action' => $next_action,
          'next_label' => $next_label,
          'current_status_tid' => $tid,
          'current_status_label' => $label,
          'current_status_slug' => $slug,
        ];
      }

      $pipeline[] = [
        'tid' => $tid,
        'label' => $label,
        'slug' => $slug,
        'count' => count($requests),
        'requests' => $requests,
      ];
    }

    return $pipeline;
  }

  /**
   * Returns contracts needing follow-up.
   */
  protected function getFollowUps(int $year): array {
    $now_ts = \Drupal::time()->getRequestTime();
    $followups = [];

    foreach (self::FOLLOWUP_THRESHOLDS as $tid => $threshold_days) {
      $threshold_ts = $now_ts - ($threshold_days * 86400);
      $label = self::PIPELINE_ORDER[$tid] ?? '';

      $query = $this->database->select('contracts_field_data', 'c');
      $query->fields('c', ['id', 'title', 'created', 'changed']);
      $query->condition('c.type', 'residential');

      $query->join('contracts__field_contract_status', 'cs', 'cs.entity_id = c.id AND cs.deleted = 0');
      $query->condition('cs.field_contract_status_target_id', $tid);

      $query->join('contracts__field_contract_year', 'cy', 'cy.entity_id = c.id AND cy.deleted = 0');
      $query->condition('cy.field_contract_year_value', $year);

      $query->leftJoin('contracts__field_property_owner', 'cpo', 'cpo.entity_id = c.id AND cpo.deleted = 0');
      $query->leftJoin('users_field_data', 'ou', 'ou.uid = cpo.field_property_owner_target_id');
      $query->addField('ou', 'name', 'owner_name');

      $query->leftJoin('contracts__field_property', 'cp', 'cp.entity_id = c.id AND cp.deleted = 0');
      $query->leftJoin('properties_field_data', 'pfd', 'pfd.id = cp.field_property_target_id');
      $query->addField('pfd', 'title', 'property_title');

      $results = $query->execute()->fetchAll();

      foreach ($results as $row) {
        $cid = (int) $row->id;
        $last_ts = $this->getLastActivityTimestamp($cid, (int) $row->changed);
        if ($last_ts > $threshold_ts) {
          continue;
        }

        $days = (int) (($now_ts - $last_ts) / 86400);

        try {
          $url = Url::fromRoute('entity.contracts.canonical', ['contracts' => $cid])->toString();
        }
        catch (\Exception $e) {
          $url = '/contracts/' . $cid;
        }

        $next_action = self::NEXT_ACTION_MAP[$tid] ?? NULL;
        $next_label = self::NEXT_LABEL_MAP[$tid] ?? NULL;
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
        $slug = trim($slug, '-');

        $followups[] = [
          'id' => $cid,
          'client' => trim($row->owner_name ?? '') ?: 'Unknown',
          'status' => $label,
          'property' => trim($row->property_title ?? '') ?: '',
          'services' => $this->getContractServices($cid),
          'days' => $days,
          'url' => $url,
          'severity' => $days >= ($threshold_days * 2) ? 'critical' : 'warning',
          'next_action' => $next_action,
          'next_label' => $next_label,
          'current_status_tid' => $tid,
          'current_status_slug' => $slug,
        ];
      }
    }

    usort($followups, fn($a, $b) => $b['days'] <=> $a['days']);
    return $followups;
  }

  /**
   * AJAX status update endpoint. Invokes VBO action plugins.
   */
  public function statusUpdate(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE) ?? [];

    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'contract_board_status_update')) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid token.'], 403);
    }

    $contract_id = (int) ($data['contract_id'] ?? 0);
    $action_id = trim((string) ($data['action_id'] ?? ''));

    if (!$contract_id || !$action_id) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Missing parameters.'], 400);
    }

    $contract = $this->entityTypeManager()
      ->getStorage('contracts')
      ->load($contract_id);

    if (!$contract) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Contract not found.'], 404);
    }

    if (!$contract->access('update')) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Access denied.'], 403);
    }

    $old_status_tid = !$contract->get('field_contract_status')->isEmpty()
      ? (int) $contract->get('field_contract_status')->target_id
      : 0;

    // Instantiate the VBO action plugin.
    try {
      $action_manager = \Drupal::service('plugin.manager.action');
      $plugin = $action_manager->createInstance($action_id);
    }
    catch (\Exception $e) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Unknown action: ' . $action_id], 400);
    }

    // For cancel action, pass the reason via configuration.
    if ($action_id === 'contract_residential_mark_canceled') {
      $reason = trim((string) ($data['cancellation_reason'] ?? ''));
      $plugin->setConfiguration(['cancellation_reason' => $reason]);
    }

    // Clear messenger before execution to capture only plugin messages.
    $messenger = \Drupal::messenger();
    $messenger->deleteAll();

    // Execute the plugin.
    $plugin->execute($contract);

    // Capture messages.
    $messages = [];
    foreach (['error', 'warning', 'status'] as $type) {
      foreach ($messenger->messagesByType($type) as $msg) {
        $messages[] = ['type' => $type, 'text' => (string) $msg];
      }
    }
    $messenger->deleteAll();

    // Reload to check if status actually changed.
    $contract = $this->entityTypeManager()
      ->getStorage('contracts')
      ->loadUnchanged($contract_id);

    $new_status_tid = !$contract->get('field_contract_status')->isEmpty()
      ? (int) $contract->get('field_contract_status')->target_id
      : 0;

    if ($new_status_tid === $old_status_tid) {
      // Status didn't change — plugin blocked it.
      $error_texts = array_filter($messages, fn($m) => $m['type'] === 'error');
      $error_msg = !empty($error_texts) ? reset($error_texts)['text'] : 'Action was blocked.';
      return new JsonResponse(['success' => FALSE, 'error' => $error_msg], 422);
    }

    // Build response data.
    $new_label = self::PIPELINE_ORDER[$new_status_tid] ?? '';
    $new_slug = '';
    if ($new_label) {
      $new_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $new_label));
      $new_slug = trim($new_slug, '-');
    }

    $off_board = !isset(self::PIPELINE_ORDER[$new_status_tid]);

    // Build row data for reinsertion.
    $client_name = 'Unknown';
    if ($contract->hasField('field_property_owner') && !$contract->get('field_property_owner')->isEmpty()) {
      $owner = $contract->get('field_property_owner')->entity;
      if ($owner) {
        $client_name = $owner->getDisplayName();
      }
    }

    $property = '';
    if ($contract->hasField('field_property') && !$contract->get('field_property')->isEmpty()) {
      $prop = $contract->get('field_property')->entity;
      if ($prop) {
        $property = (string) ($prop->label() ?? '');
      }
    }

    $year = 0;
    if ($contract->hasField('field_contract_year') && !$contract->get('field_contract_year')->isEmpty()) {
      $year = (int) $contract->get('field_contract_year')->value;
    }

    try {
      $url = Url::fromRoute('entity.contracts.canonical', ['contracts' => $contract_id])->toString();
    }
    catch (\Exception $e) {
      $url = '/contracts/' . $contract_id;
    }

    $next_action = self::NEXT_ACTION_MAP[$new_status_tid] ?? NULL;
    $next_label = self::NEXT_LABEL_MAP[$new_status_tid] ?? NULL;

    return new JsonResponse([
      'success' => TRUE,
      'contract_id' => $contract_id,
      'new_status_tid' => $new_status_tid,
      'new_status' => $new_label,
      'new_status_slug' => $new_slug,
      'off_board' => $off_board,
      'client_name' => $client_name,
      'property' => $property,
      'services' => $this->getContractServices($contract_id),
      'year' => $year,
      'age_days' => $this->getContractAgeDays($contract_id, (int) $contract->getCreatedTime()),
      'url' => $url,
      'next_action' => $next_action,
      'next_label' => $next_label,
      'messages' => $messages,
    ]);
  }

  /**
   * Completed tab.
   */
  public function completedTab(Request $request): array {
    $days = (int) $request->query->get('days', 90);
    $rows = $this->getTerminalContracts(1127, $days);
    return [
      '#theme' => 'contract_board_completed',
      '#rows' => $rows,
      '#days' => $days,
      '#attached' => ['library' => ['contract_board/contract_board']],
    ];
  }

  /**
   * Canceled tab.
   */
  public function canceledTab(Request $request): array {
    $days = (int) $request->query->get('days', 90);
    $rows = $this->getTerminalContracts(1128, $days);
    return [
      '#theme' => 'contract_board_canceled',
      '#rows' => $rows,
      '#days' => $days,
      '#attached' => ['library' => ['contract_board/contract_board']],
    ];
  }

  /**
   * Loads contracts in a terminal status for the tab views.
   */
  protected function getTerminalContracts(int $status_tid, int $days): array {
    $cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);

    $query = $this->database->select('contracts_field_data', 'c');
    $query->fields('c', ['id', 'title', 'changed']);
    $query->condition('c.type', 'residential');
    $query->condition('c.changed', $cutoff, '>=');

    $query->join('contracts__field_contract_status', 'cs', 'cs.entity_id = c.id AND cs.deleted = 0');
    $query->condition('cs.field_contract_status_target_id', $status_tid);

    $query->leftJoin('contracts__field_property_owner', 'cpo', 'cpo.entity_id = c.id AND cpo.deleted = 0');
    $query->leftJoin('users_field_data', 'ou', 'ou.uid = cpo.field_property_owner_target_id');
    $query->addField('ou', 'name', 'owner_name');

    $query->leftJoin('contracts__field_property', 'cp', 'cp.entity_id = c.id AND cp.deleted = 0');
    $query->leftJoin('properties_field_data', 'pfd', 'pfd.id = cp.field_property_target_id');
    $query->addField('pfd', 'title', 'property_title');

    $query->orderBy('c.changed', 'DESC');
    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      try {
        $url = Url::fromRoute('entity.contracts.canonical', ['contracts' => $row->id])->toString();
      }
      catch (\Exception $e) {
        $url = '/contracts/' . $row->id;
      }

      $rows[] = [
        'id' => (int) $row->id,
        'client' => trim($row->owner_name ?? '') ?: 'Unknown',
        'property' => trim($row->property_title ?? '') ?: '',
        'services' => $this->getContractServices((int) $row->id),
        'date' => $this->dateFormatter->format((int) $row->changed, 'short'),
        'url' => $url,
      ];
    }

    return $rows;
  }

  // ── Helpers ────────────────────────────────────────────────────

  /**
   * Returns the age in days since last contract_action_log entry, or fallback.
   */
  protected function getContractAgeDays(int $contract_id, int $fallback_created): int {
    $last_ts = $this->getLastActivityTimestamp($contract_id, $fallback_created);
    return (int) ((\Drupal::time()->getRequestTime() - $last_ts) / 86400);
  }

  /**
   * Returns the most recent activity timestamp for a contract.
   */
  protected function getLastActivityTimestamp(int $contract_id, int $fallback_changed): int {
    $query = $this->database->select('contract_action_log_field_data', 'cal');
    $query->fields('cal', ['created']);
    $query->join('contract_action_log__field_contract', 'fc', 'fc.entity_id = cal.id AND fc.deleted = 0');
    $query->condition('fc.field_contract_target_id', $contract_id);
    $query->orderBy('cal.created', 'DESC');
    $query->range(0, 1);
    $ts = $query->execute()->fetchField();
    return $ts ? (int) $ts : $fallback_changed;
  }

  /**
   * Returns comma-separated service labels from contract sections.
   */
  protected function getContractServices(int $contract_id): string {
    $query = $this->database->select('contract_sections__field_contract', 'csc');
    $query->condition('csc.field_contract_target_id', $contract_id);
    $query->condition('csc.deleted', 0);
    $query->join('contract_sections__field_service', 'css', 'css.entity_id = csc.entity_id AND css.deleted = 0');
    $query->join('taxonomy_term_field_data', 'svc', 'svc.tid = css.field_service_target_id');
    $query->addField('svc', 'name');

    // Only include agreed sections (Yes=1 or Accepted=4).
    $query->leftJoin('contract_sections__field_do_you_want', 'dyw', 'dyw.entity_id = csc.entity_id AND dyw.deleted = 0');
    $query->condition('dyw.field_do_you_want_value', ['1', '4'], 'IN');

    $query->orderBy('svc.name');
    $services = $query->execute()->fetchCol();
    return implode(', ', array_unique($services));
  }

}
