<?php

namespace Drupal\estimate_board\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Estimate Board dashboard controller.
 */
class EstimateBoardController extends ControllerBase {

  protected Connection $database;
  protected DateFormatterInterface $dateFormatter;

  /**
   * Terminal statuses excluded from active pipeline and follow-ups.
   */
  const CLOSED_STATUSES = [1657, 1658];

  /**
   * Active pipeline statuses in display order.
   * Converted (1658) and Declined (1657) are excluded — they have their own tabs.
   */
  const PIPELINE_ORDER = [
    1652 => 'New',
    1653 => 'Needs Info',
    1654 => 'Appointment Scheduled',
    1655 => 'Estimating',
    1810 => 'Estimate Sent',
    1656 => 'Waiting on Customer',
  ];

  /**
   * Declined TID — passed to template for the ✕ button.
   */
  const DECLINED_TID = 1657;

  /**
   * Follow-up threshold in days.
   */
  const FOLLOWUP_DAYS = 5;
  const CRITICAL_DAYS = 10;

  /**
   * Closed estimate stage TIDs (Accepted, Declined).
   */
  const CLOSED_STAGES = [1418, 1419];

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
   * Renders the Estimate Board dashboard.
   */
  public function board(): array {
    return [
      '#theme' => 'estimate_board',
      '#followups' => $this->getFollowUps(),
      '#pipeline' => $this->getPipeline(),
      '#workload' => $this->getWorkload(),
      '#activity' => $this->getRecentActivity(),
      '#decline_tid' => self::DECLINED_TID,
      '#csrf_token' => \Drupal::csrfToken()->get('estimate_board_status_update'),
      '#attached' => [
        'library' => ['estimate_board/estimate_board'],
        'drupalSettings' => [
          'estimateBoard' => [
            'csrfToken' => \Drupal::csrfToken()->get('estimate_board_status_update'),
          ],
        ],
      ],
    ];
  }

  /**
   * Returns estimate requests needing follow-up (5+ days no activity).
   *
   * Public so hook_cron can call it.
   */
  public function getFollowUps(): array {
    $site_tz = new \DateTimeZone(date_default_timezone_get());
    $now = new \DateTime('now', $site_tz);
    $threshold = (clone $now)->modify('-' . self::FOLLOWUP_DAYS . ' days')->getTimestamp();

    $query = $this->database->select('estimate_request_field_data', 'er');
    $query->fields('er', ['id', 'title', 'changed']);
    $query->join('estimate_request__field_status', 'ers', 'ers.entity_id = er.id AND ers.deleted = 0');
    $query->condition('ers.field_status_target_id', self::CLOSED_STATUSES, 'NOT IN');

    $query->leftJoin('estimate_request__field_owner', 'ero', 'ero.entity_id = er.id AND ero.deleted = 0');
    $query->leftJoin('users_field_data', 'ou', 'ou.uid = ero.field_owner_target_id');
    $query->addField('ou', 'name', 'owner_name');

    $query->leftJoin('estimate_request__field_requestor_name', 'ern', 'ern.entity_id = er.id AND ern.deleted = 0');
    $query->addField('ern', 'field_requestor_name_value', 'requestor_name');

    $query->leftJoin('estimate_request__field_assigned_to', 'era', 'era.entity_id = er.id AND era.deleted = 0');
    $query->leftJoin('users_field_data', 'au', 'au.uid = era.field_assigned_to_target_id');
    $query->addField('au', 'name', 'assigned_name');

    $query->leftJoin('taxonomy_term_field_data', 'stterm', 'stterm.tid = ers.field_status_target_id');
    $query->addField('stterm', 'name', 'status_label');

    $query->leftJoin('estimate_request__field_property', 'erp', 'erp.entity_id = er.id AND erp.deleted = 0');
    $query->leftJoin('properties__field_nickname', 'pnick', 'pnick.entity_id = erp.field_property_target_id AND pnick.deleted = 0');
    $query->addField('pnick', 'field_nickname_value', 'property_name');

    $results = $query->execute()->fetchAll();

    $followups = [];
    foreach ($results as $row) {
      $last_ts = $this->getLastActivityTimestamp((int) $row->id, (int) $row->changed);
      if ($last_ts > $threshold) {
        continue;
      }

      $days = (int) (($now->getTimestamp() - $last_ts) / 86400);
      $last_action = $this->getLastActionDescription((int) $row->id);

      try {
        $url = Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $row->id])->toString();
      }
      catch (\Exception $e) {
        $url = '/estimate_request/' . $row->id;
      }

      $client = trim($row->owner_name ?? '') ?: trim($row->requestor_name ?? '') ?: 'Unknown';
      $services = $this->getRequestServices((int) $row->id);

      $followups[] = [
        'id' => (int) $row->id,
        'client' => $client,
        'status' => trim($row->status_label ?? '') ?: '—',
        'property' => trim($row->property_name ?? '') ?: '',
        'services' => $services,
        'assigned' => trim($row->assigned_name ?? '') ?: 'Unassigned',
        'days' => $days,
        'last_action' => $last_action,
        'url' => $url,
        'severity' => $days >= self::CRITICAL_DAYS ? 'critical' : 'warning',
      ];
    }

    usort($followups, fn($a, $b) => $b['days'] <=> $a['days']);

    return $followups;
  }

  /**
   * Returns active pipeline grouped by status.
   */
  protected function getPipeline(): array {
    $pipeline = [];
    $order_keys = array_keys(self::PIPELINE_ORDER);

    foreach (self::PIPELINE_ORDER as $tid => $label) {
      $now_ts = \Drupal::time()->getRequestTime();

      $query = $this->database->select('estimate_request_field_data', 'er');
      $query->fields('er', ['id', 'title', 'created']);
      $query->join('estimate_request__field_status', 'ers', 'ers.entity_id = er.id AND ers.deleted = 0');
      $query->condition('ers.field_status_target_id', $tid);

      // Owner/requestor name.
      $query->leftJoin('estimate_request__field_owner', 'ero', 'ero.entity_id = er.id AND ero.deleted = 0');
      $query->leftJoin('users_field_data', 'ou', 'ou.uid = ero.field_owner_target_id');
      $query->addField('ou', 'name', 'owner_name');
      $query->leftJoin('estimate_request__field_requestor_name', 'ern', 'ern.entity_id = er.id AND ern.deleted = 0');
      $query->addField('ern', 'field_requestor_name_value', 'requestor_name');

      // Coordinator.
      $query->leftJoin('estimate_request__field_assigned_to', 'era', 'era.entity_id = er.id AND era.deleted = 0');
      $query->leftJoin('users_field_data', 'au', 'au.uid = era.field_assigned_to_target_id');
      $query->addField('au', 'name', 'assigned_name');

      // Property.
      $query->leftJoin('estimate_request__field_property', 'erp', 'erp.entity_id = er.id AND erp.deleted = 0');
      $query->leftJoin('properties__field_nickname', 'pnick', 'pnick.entity_id = erp.field_property_target_id AND pnick.deleted = 0');
      $query->addField('pnick', 'field_nickname_value', 'property_name');

      $query->orderBy('er.changed', 'DESC');
      $results = $query->execute()->fetchAll();

      // Compute prev/next status.
      $current_index = array_search($tid, $order_keys);
      $prev_tid = $current_index > 0 ? $order_keys[$current_index - 1] : NULL;
      $next_tid = ($current_index !== FALSE && $current_index < count($order_keys) - 1) ? $order_keys[$current_index + 1] : NULL;
      $prev_label = $prev_tid ? self::PIPELINE_ORDER[$prev_tid] : NULL;
      $next_label = $next_tid ? self::PIPELINE_ORDER[$next_tid] : NULL;

      $requests = [];
      foreach ($results as $row) {
        try {
          $url = Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $row->id])->toString();
        }
        catch (\Exception $e) {
          $url = '/estimate_request/' . $row->id;
        }

        $client = trim($row->owner_name ?? '') ?: trim($row->requestor_name ?? '') ?: 'Unknown';
        $age_days = (int) (($now_ts - (int) $row->created) / 86400);

        $requests[] = [
          'id' => (int) $row->id,
          'title' => $row->title,
          'client_name' => $client,
          'property' => trim($row->property_name ?? '') ?: '',
          'services' => $this->getRequestServices((int) $row->id),
          'coordinator' => trim($row->assigned_name ?? '') ?: 'Unassigned',
          'age_days' => $age_days,
          'url' => $url,
          'estimates' => $this->getRequestEstimatesSimple((int) $row->id),
          'prev_status_tid' => $prev_tid,
          'prev_status_label' => $prev_label,
          'next_status_tid' => $next_tid,
          'next_status_label' => $next_label,
        ];
      }

      $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
      $slug = trim($slug, '-');
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
   * Returns simplified estimate data for pipeline rows (no stage info).
   */
  protected function getRequestEstimatesSimple(int $request_id): array {
    static $bundle_info = NULL;
    if ($bundle_info === NULL) {
      $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('estimate');
    }

    $estimate_request = $this->entityTypeManager()
      ->getStorage('estimate_request')
      ->load($request_id);
    if (!$estimate_request || $estimate_request->get('field_estimates')->isEmpty()) {
      return [];
    }

    $estimate_ids = array_column(
      $estimate_request->get('field_estimates')->getValue(),
      'target_id'
    );
    if (empty($estimate_ids)) {
      return [];
    }

    $estimates = $this->entityTypeManager()
      ->getStorage('estimate')
      ->loadMultiple($estimate_ids);

    $rows = [];
    foreach ($estimates as $estimate) {
      $bundle = $estimate->bundle();
      $label = $bundle_info[$bundle]['label'] ?? $bundle;

      $total = '';
      if ($estimate->hasField('field_estimate_total') && !$estimate->get('field_estimate_total')->isEmpty()) {
        $total_value = (float) $estimate->get('field_estimate_total')->value;
        if ($total_value > 0) {
          $total = '$' . number_format($total_value, 2);
        }
      }

      try {
        $url = Url::fromRoute('entity.estimate.canonical', ['estimate' => $estimate->id()])->toString();
      }
      catch (\Exception $e) {
        $url = '/estimate/' . $estimate->id();
      }

      $rows[] = [
        'id' => (int) $estimate->id(),
        'label' => (string) $label,
        'total' => $total,
        'url' => $url,
      ];
    }

    return $rows;
  }

  /**
   * Accepted tab: shows Converted requests and requests with accepted estimates.
   */
  public function acceptedTab(Request $request): array {
    $days = (int) $request->query->get('days', 90);
    $cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);

    $query = $this->database->select('estimate_request_field_data', 'er');
    $query->fields('er', ['id', 'title', 'changed']);
    $query->join('estimate_request__field_status', 'ers', 'ers.entity_id = er.id AND ers.deleted = 0');
    $query->condition('ers.field_status_target_id', 1658); // Converted.
    $query->condition('er.changed', $cutoff, '>=');

    $query->leftJoin('estimate_request__field_owner', 'ero', 'ero.entity_id = er.id AND ero.deleted = 0');
    $query->leftJoin('users_field_data', 'ou', 'ou.uid = ero.field_owner_target_id');
    $query->addField('ou', 'name', 'owner_name');
    $query->leftJoin('estimate_request__field_requestor_name', 'ern', 'ern.entity_id = er.id AND ern.deleted = 0');
    $query->addField('ern', 'field_requestor_name_value', 'requestor_name');
    $query->leftJoin('estimate_request__field_assigned_to', 'era', 'era.entity_id = er.id AND era.deleted = 0');
    $query->leftJoin('users_field_data', 'au', 'au.uid = era.field_assigned_to_target_id');
    $query->addField('au', 'name', 'assigned_name');
    $query->leftJoin('estimate_request__field_property', 'erp', 'erp.entity_id = er.id AND erp.deleted = 0');
    $query->leftJoin('properties__field_nickname', 'pnick', 'pnick.entity_id = erp.field_property_target_id AND pnick.deleted = 0');
    $query->addField('pnick', 'field_nickname_value', 'property_name');

    $query->orderBy('er.changed', 'DESC');
    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      try {
        $url = Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $row->id])->toString();
      }
      catch (\Exception $e) {
        $url = '/estimate_request/' . $row->id;
      }

      $client = trim($row->owner_name ?? '') ?: trim($row->requestor_name ?? '') ?: 'Unknown';

      $rows[] = [
        'id' => (int) $row->id,
        'client' => $client,
        'property' => trim($row->property_name ?? '') ?: '',
        'services' => $this->getRequestServices((int) $row->id),
        'coordinator' => trim($row->assigned_name ?? '') ?: 'Unassigned',
        'date' => $this->dateFormatter->format((int) $row->changed, 'short'),
        'url' => $url,
        'estimates' => $this->getRequestEstimatesSimple((int) $row->id),
      ];
    }

    return [
      '#theme' => 'estimate_board_accepted',
      '#rows' => $rows,
      '#days' => $days,
      '#attached' => [
        'library' => ['estimate_board/estimate_board'],
      ],
    ];
  }

  /**
   * Declined tab: shows Declined requests.
   */
  public function declinedTab(Request $request): array {
    $days = (int) $request->query->get('days', 90);
    $cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);

    $query = $this->database->select('estimate_request_field_data', 'er');
    $query->fields('er', ['id', 'title', 'changed']);
    $query->join('estimate_request__field_status', 'ers', 'ers.entity_id = er.id AND ers.deleted = 0');
    $query->condition('ers.field_status_target_id', 1657); // Declined.
    $query->condition('er.changed', $cutoff, '>=');

    $query->leftJoin('estimate_request__field_owner', 'ero', 'ero.entity_id = er.id AND ero.deleted = 0');
    $query->leftJoin('users_field_data', 'ou', 'ou.uid = ero.field_owner_target_id');
    $query->addField('ou', 'name', 'owner_name');
    $query->leftJoin('estimate_request__field_requestor_name', 'ern', 'ern.entity_id = er.id AND ern.deleted = 0');
    $query->addField('ern', 'field_requestor_name_value', 'requestor_name');
    $query->leftJoin('estimate_request__field_assigned_to', 'era', 'era.entity_id = er.id AND era.deleted = 0');
    $query->leftJoin('users_field_data', 'au', 'au.uid = era.field_assigned_to_target_id');
    $query->addField('au', 'name', 'assigned_name');
    $query->leftJoin('estimate_request__field_property', 'erp', 'erp.entity_id = er.id AND erp.deleted = 0');
    $query->leftJoin('properties__field_nickname', 'pnick', 'pnick.entity_id = erp.field_property_target_id AND pnick.deleted = 0');
    $query->addField('pnick', 'field_nickname_value', 'property_name');

    $query->orderBy('er.changed', 'DESC');
    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      try {
        $url = Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $row->id])->toString();
      }
      catch (\Exception $e) {
        $url = '/estimate_request/' . $row->id;
      }

      $client = trim($row->owner_name ?? '') ?: trim($row->requestor_name ?? '') ?: 'Unknown';

      $rows[] = [
        'id' => (int) $row->id,
        'client' => $client,
        'property' => trim($row->property_name ?? '') ?: '',
        'services' => $this->getRequestServices((int) $row->id),
        'coordinator' => trim($row->assigned_name ?? '') ?: 'Unassigned',
        'date' => $this->dateFormatter->format((int) $row->changed, 'short'),
        'url' => $url,
      ];
    }

    return [
      '#theme' => 'estimate_board_declined',
      '#rows' => $rows,
      '#days' => $days,
      '#attached' => [
        'library' => ['estimate_board/estimate_board'],
      ],
    ];
  }

  /**
   * Returns estimator workload summary.
   */
  protected function getWorkload(): array {
    $query = $this->database->select('estimate_field_data', 'e');
    $query->addExpression('COUNT(e.id)', 'estimate_count');
    $query->join('estimate__field_is_current_revision', 'icr', 'icr.entity_id = e.id AND icr.deleted = 0');
    $query->condition('icr.field_is_current_revision_value', 1);
    $query->join('estimate__field_stage', 'es', 'es.entity_id = e.id AND es.deleted = 0');
    $query->condition('es.field_stage_target_id', self::CLOSED_STAGES, 'NOT IN');
    $query->leftJoin('estimate__field_assigned_to', 'ea', 'ea.entity_id = e.id AND ea.deleted = 0');
    $query->leftJoin('users_field_data', 'au', 'au.uid = ea.field_assigned_to_target_id');
    $query->addField('au', 'uid', 'estimator_uid');
    $query->addField('au', 'name', 'estimator_name');
    $query->groupBy('au.uid');
    $query->groupBy('au.name');
    $query->orderBy('estimate_count', 'DESC');

    $current_uid = (int) \Drupal::currentUser()->id();
    $workload = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $uid = (int) ($row->estimator_uid ?? 0);
      $workload[] = [
        'uid' => $uid,
        'name' => trim($row->estimator_name ?? '') ?: 'Unassigned',
        'count' => (int) $row->estimate_count,
        'is_current' => ($uid === $current_uid),
      ];
    }

    return $workload;
  }

  /**
   * Returns recent activity from estimate_action_log (last 48 hours).
   */
  protected function getRecentActivity(): array {
    $cutoff = \Drupal::time()->getRequestTime() - (48 * 3600);

    $query = $this->database->select('estimate_action_log_field_data', 'eal');
    $query->fields('eal', ['id', 'created', 'uid', 'type']);
    $query->condition('eal.created', $cutoff, '>=');
    $query->leftJoin('estimate_action_log__field_action', 'fa', 'fa.entity_id = eal.id AND fa.deleted = 0');
    $query->addField('fa', 'field_action_value', 'action');
    $query->leftJoin('estimate_action_log__field_context', 'fc', 'fc.entity_id = eal.id AND fc.deleted = 0');
    $query->addField('fc', 'field_context_value', 'context');
    $query->leftJoin('users_field_data', 'u', 'u.uid = eal.uid');
    $query->addField('u', 'name', 'user_name');
    $query->leftJoin('estimate_action_log__field_estimate', 'fe', 'fe.entity_id = eal.id AND fe.deleted = 0');
    $query->addField('fe', 'field_estimate_target_id', 'estimate_id');
    $query->leftJoin('estimate_action_log__field_request', 'fr', 'fr.entity_id = eal.id AND fr.deleted = 0');
    $query->addField('fr', 'field_request_target_id', 'request_id');
    $query->orderBy('eal.created', 'DESC');
    $query->range(0, 15);

    $activity = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $url = '';
      if (!empty($row->request_id)) {
        try { $url = Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $row->request_id])->toString(); }
        catch (\Exception $e) {}
      }
      elseif (!empty($row->estimate_id)) {
        try { $url = Url::fromRoute('entity.estimate.canonical', ['estimate' => $row->estimate_id])->toString(); }
        catch (\Exception $e) {}
      }

      $activity[] = [
        'date' => $this->dateFormatter->format((int) $row->created, 'short'),
        'user' => trim($row->user_name ?? '') ?: 'System',
        'action' => trim($row->action ?? ''),
        'context' => trim($row->context ?? ''),
        'url' => $url,
        'type' => $row->type,
      ];
    }

    return $activity;
  }

  /**
   * AJAX status update endpoint. Returns JSON.
   */
  public function statusUpdate(Request $request): JsonResponse {
    // Support both JSON body and form POST.
    $content_type = $request->headers->get('Content-Type', '');
    if (str_contains($content_type, 'application/json')) {
      $data = json_decode($request->getContent(), TRUE) ?? [];
      $request_id = (int) ($data['estimate_request_id'] ?? 0);
      $new_status = (int) ($data['new_status_tid'] ?? 0);
    }
    else {
      $request_id = (int) $request->request->get('request_id', 0);
      $new_status = (int) $request->request->get('new_status', 0);
    }

    // Validate CSRF token (passed via drupalSettings → JS → header).
    $token = $request->headers->get('X-CSRF-Token')
      ?: $request->request->get('token', '');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'estimate_board_status_update')) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid token.'], 403);
    }

    $all_statuses = array_merge(array_keys(self::PIPELINE_ORDER), [self::DECLINED_TID]);
    if (!$request_id || !in_array($new_status, $all_statuses, TRUE)) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid request or status.'], 400);
    }

    $estimate_request = $this->entityTypeManager()
      ->getStorage('estimate_request')->load($request_id);
    if (!$estimate_request) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Request not found.'], 404);
    }

    $estimate_request->set('field_status', ['target_id' => $new_status]);
    $estimate_request->save();

    $term = $this->entityTypeManager()
      ->getStorage('taxonomy_term')->load($new_status);
    $label = $term ? $term->label() : (string) $new_status;
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
    $slug = trim($slug, '-');

    // Build row data for the destination swimlane.
    $order_keys = array_keys(self::PIPELINE_ORDER);
    $new_index = array_search($new_status, $order_keys);
    $prev_tid = ($new_index !== FALSE && $new_index > 0) ? $order_keys[$new_index - 1] : NULL;
    $next_tid = ($new_index !== FALSE && $new_index < count($order_keys) - 1) ? $order_keys[$new_index + 1] : NULL;

    // Owner/requestor.
    $client_name = 'Unknown';
    if ($estimate_request->hasField('field_owner') && !$estimate_request->get('field_owner')->isEmpty()) {
      $owner = $estimate_request->get('field_owner')->entity;
      if ($owner) {
        $client_name = $owner->getDisplayName();
      }
    }
    if ($client_name === 'Unknown' && $estimate_request->hasField('field_requestor_name') && !$estimate_request->get('field_requestor_name')->isEmpty()) {
      $client_name = trim((string) $estimate_request->get('field_requestor_name')->value);
    }

    // Property.
    $property = '';
    if ($estimate_request->hasField('field_property') && !$estimate_request->get('field_property')->isEmpty()) {
      $prop = $estimate_request->get('field_property')->entity;
      if ($prop && $prop->hasField('field_nickname') && !$prop->get('field_nickname')->isEmpty()) {
        $property = (string) $prop->get('field_nickname')->value;
      }
    }

    // Coordinator.
    $coordinator = 'Unassigned';
    if ($estimate_request->hasField('field_assigned_to') && !$estimate_request->get('field_assigned_to')->isEmpty()) {
      $assigned = $estimate_request->get('field_assigned_to')->entity;
      if ($assigned) {
        $coordinator = $assigned->getDisplayName();
      }
    }

    // Age.
    $age_days = (int) ((\Drupal::time()->getRequestTime() - (int) $estimate_request->getCreatedTime()) / 86400);

    // URL.
    try {
      $url = Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $request_id])->toString();
    }
    catch (\Exception $e) {
      $url = '/estimate_request/' . $request_id;
    }

    return new JsonResponse([
      'success' => TRUE,
      'new_status' => $label,
      'new_status_slug' => $slug,
      'request_id' => $request_id,
      'client_name' => $client_name,
      'property' => $property,
      'services' => $this->getRequestServices($request_id),
      'coordinator' => $coordinator,
      'age_days' => $age_days,
      'estimates' => $this->getRequestEstimatesSimple($request_id),
      'prev_status_tid' => $prev_tid,
      'prev_status_label' => $prev_tid ? self::PIPELINE_ORDER[$prev_tid] : NULL,
      'next_status_tid' => $next_tid,
      'next_status_label' => $next_tid ? self::PIPELINE_ORDER[$next_tid] : NULL,
      'decline_tid' => self::DECLINED_TID,
      'url' => $url,
    ]);
  }

  // ── Helper methods ──────────────────────────────────────────────

  protected function getLastActivityTimestamp(int $request_id, int $fallback_changed): int {
    $result = $this->database->select('estimate_action_log_field_data', 'eal')
      ->fields('eal', ['created'])
      ->condition('eal.type', 'request_log');
    $result->join('estimate_action_log__field_request', 'fr', 'fr.entity_id = eal.id AND fr.deleted = 0');
    $result->condition('fr.field_request_target_id', $request_id);
    $result->orderBy('eal.created', 'DESC');
    $result->range(0, 1);
    $ts = $result->execute()->fetchField();
    return $ts ? (int) $ts : $fallback_changed;
  }

  protected function getLastActionDescription(int $request_id): string {
    $query = $this->database->select('estimate_action_log_field_data', 'eal');
    $query->fields('eal', ['created']);
    $query->condition('eal.type', 'request_log');
    $query->join('estimate_action_log__field_request', 'fr', 'fr.entity_id = eal.id AND fr.deleted = 0');
    $query->condition('fr.field_request_target_id', $request_id);
    $query->leftJoin('estimate_action_log__field_action', 'fa', 'fa.entity_id = eal.id AND fa.deleted = 0');
    $query->addField('fa', 'field_action_value', 'action');
    $query->leftJoin('estimate_action_log__field_context', 'fc', 'fc.entity_id = eal.id AND fc.deleted = 0');
    $query->addField('fc', 'field_context_value', 'context');
    $query->orderBy('eal.created', 'DESC');
    $query->range(0, 1);
    $row = $query->execute()->fetch();
    return $row ? trim($row->context ?? $row->action ?? '') : '';
  }

  protected function getRequestServices(int $request_id): string {
    $query = $this->database->select('estimate_request__field_service', 'esvc');
    $query->condition('esvc.entity_id', $request_id);
    $query->condition('esvc.deleted', 0);
    $query->join('taxonomy_term_field_data', 'svc', 'svc.tid = esvc.field_service_target_id');
    $query->addField('svc', 'name');
    $query->orderBy('svc.name');
    return implode(', ', $query->execute()->fetchCol());
  }

}
