<?php

namespace Drupal\estimate_board\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Estimate Board dashboard controller.
 */
class EstimateBoardController extends ControllerBase {

  protected Connection $database;
  protected DateFormatterInterface $dateFormatter;

  /**
   * Status TIDs to exclude from active pipeline.
   */
  const CLOSED_STATUSES = [1657, 1658];

  /**
   * Active pipeline statuses in display order.
   */
  const PIPELINE_ORDER = [
    1652 => 'New',
    1653 => 'Needs Info',
    1654 => 'Ready to Estimate',
    1655 => 'Estimating In Progress',
    1656 => 'Waiting on Client',
    1658 => 'Converted',
    1657 => 'Declined / Canceled',
  ];

  /**
   * Follow-up threshold in days.
   */
  const FOLLOWUP_DAYS = 5;
  const CRITICAL_DAYS = 10;

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
      '#csrf_token' => \Drupal::csrfToken()->get('estimate_board_status_update'),
      '#attached' => [
        'library' => ['estimate_board/estimate_board'],
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

    // Get all active requests.
    $query = $this->database->select('estimate_request_field_data', 'er');
    $query->fields('er', ['id', 'title', 'changed']);
    $query->join('estimate_request__field_status', 'ers', 'ers.entity_id = er.id AND ers.deleted = 0');
    $query->condition('ers.field_status_target_id', self::CLOSED_STATUSES, 'NOT IN');

    // Owner name.
    $query->leftJoin('estimate_request__field_owner', 'ero', 'ero.entity_id = er.id AND ero.deleted = 0');
    $query->leftJoin('users_field_data', 'ou', 'ou.uid = ero.field_owner_target_id');
    $query->addField('ou', 'name', 'owner_name');

    // Requestor name fallback.
    $query->leftJoin('estimate_request__field_requestor_name', 'ern', 'ern.entity_id = er.id AND ern.deleted = 0');
    $query->addField('ern', 'field_requestor_name_value', 'requestor_name');

    // Assigned estimator.
    $query->leftJoin('estimate_request__field_assigned_to', 'era', 'era.entity_id = er.id AND era.deleted = 0');
    $query->leftJoin('users_field_data', 'au', 'au.uid = era.field_assigned_to_target_id');
    $query->addField('au', 'name', 'assigned_name');

    // Status label.
    $query->leftJoin('taxonomy_term_field_data', 'stterm', 'stterm.tid = ers.field_status_target_id');
    $query->addField('stterm', 'name', 'status_label');

    // Property address.
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
        'estimates' => $this->getRequestEstimates((int) $row->id),
      ];
    }

    // Sort by days since activity DESC.
    usort($followups, fn($a, $b) => $b['days'] <=> $a['days']);

    return $followups;
  }

  /**
   * Returns active pipeline grouped by status.
   */
  protected function getPipeline(): array {
    $pipeline = [];

    foreach (self::PIPELINE_ORDER as $tid => $label) {
      $query = $this->database->select('estimate_request_field_data', 'er');
      $query->fields('er', ['id', 'title']);
      $query->join('estimate_request__field_status', 'ers', 'ers.entity_id = er.id AND ers.deleted = 0');
      $query->condition('ers.field_status_target_id', $tid);

      // Owner/requestor name.
      $query->leftJoin('estimate_request__field_owner', 'ero', 'ero.entity_id = er.id AND ero.deleted = 0');
      $query->leftJoin('users_field_data', 'ou', 'ou.uid = ero.field_owner_target_id');
      $query->addField('ou', 'name', 'owner_name');
      $query->leftJoin('estimate_request__field_requestor_name', 'ern', 'ern.entity_id = er.id AND ern.deleted = 0');
      $query->addField('ern', 'field_requestor_name_value', 'requestor_name');

      $query->orderBy('er.changed', 'DESC');
      $results = $query->execute()->fetchAll();

      $items = [];
      foreach ($results as $row) {
        try {
          $url = Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $row->id])->toString();
        }
        catch (\Exception $e) {
          $url = '/estimate_request/' . $row->id;
        }

        $client = trim($row->owner_name ?? '') ?: trim($row->requestor_name ?? '') ?: 'Unknown';
        $items[] = [
          'id' => (int) $row->id,
          'title' => $row->title,
          'client' => $client,
          'url' => $url,
          'estimates' => $this->getRequestEstimates((int) $row->id),
        ];
      }

      $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
      $slug = trim($slug, '-');
      $pipeline[] = [
        'tid' => $tid,
        'label' => $label,
        'slug' => $slug,
        'count' => count($items),
        'items' => $items,
      ];
    }

    return $pipeline;
  }

  /**
   * Closed estimate stage TIDs (Accepted, Declined).
   */
  const CLOSED_STAGES = [1418, 1419];

  /**
   * Returns estimator workload summary.
   *
   * Counts estimate entities (not requests) grouped by estimate.field_assigned_to.
   * Only counts active revision, excludes Accepted and Declined stages.
   */
  protected function getWorkload(): array {
    $query = $this->database->select('estimate_field_data', 'e');
    $query->addExpression('COUNT(e.id)', 'estimate_count');
    // Only current revisions.
    $query->join('estimate__field_is_current_revision', 'icr', 'icr.entity_id = e.id AND icr.deleted = 0');
    $query->condition('icr.field_is_current_revision_value', 1);
    // Exclude closed stages.
    $query->join('estimate__field_stage', 'es', 'es.entity_id = e.id AND es.deleted = 0');
    $query->condition('es.field_stage_target_id', self::CLOSED_STAGES, 'NOT IN');
    // Estimator assignment.
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

    // Action.
    $query->leftJoin('estimate_action_log__field_action', 'fa', 'fa.entity_id = eal.id AND fa.deleted = 0');
    $query->addField('fa', 'field_action_value', 'action');

    // Context.
    $query->leftJoin('estimate_action_log__field_context', 'fc', 'fc.entity_id = eal.id AND fc.deleted = 0');
    $query->addField('fc', 'field_context_value', 'context');

    // User name.
    $query->leftJoin('users_field_data', 'u', 'u.uid = eal.uid');
    $query->addField('u', 'name', 'user_name');

    // Estimate reference (log bundle).
    $query->leftJoin('estimate_action_log__field_estimate', 'fe', 'fe.entity_id = eal.id AND fe.deleted = 0');
    $query->addField('fe', 'field_estimate_target_id', 'estimate_id');

    // Request reference (request_log bundle).
    $query->leftJoin('estimate_action_log__field_request', 'fr', 'fr.entity_id = eal.id AND fr.deleted = 0');
    $query->addField('fr', 'field_request_target_id', 'request_id');

    $query->orderBy('eal.created', 'DESC');
    $query->range(0, 15);

    $activity = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $url = '';
      if (!empty($row->request_id)) {
        try {
          $url = Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $row->request_id])->toString();
        }
        catch (\Exception $e) {}
      }
      elseif (!empty($row->estimate_id)) {
        try {
          $url = Url::fromRoute('entity.estimate.canonical', ['estimate' => $row->estimate_id])->toString();
        }
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
   * Returns the timestamp of the most recent action log entry for a request.
   */
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

  /**
   * Returns a human-readable description of the last action on a request.
   */
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
    if (!$row) {
      return '';
    }

    return trim($row->context ?? $row->action ?? '');
  }

  /**
   * Returns comma-separated service names for an estimate request.
   */
  protected function getRequestServices(int $request_id): string {
    $query = $this->database->select('estimate_request__field_service', 'esvc');
    $query->condition('esvc.entity_id', $request_id);
    $query->condition('esvc.deleted', 0);
    $query->join('taxonomy_term_field_data', 'svc', 'svc.tid = esvc.field_service_target_id');
    $query->addField('svc', 'name');
    $query->orderBy('svc.name');
    $names = $query->execute()->fetchCol();
    return implode(', ', $names);
  }

  /**
   * Returns nested estimate data for an estimate_request.
   *
   * For each estimate referenced by field_estimates, returns an array with
   * label, stage, stage_key, total, and url for template rendering.
   *
   * @param int $request_id
   *   The estimate_request entity ID.
   *
   * @return array
   *   List of estimate row arrays. Empty array if request has no estimates.
   */
  protected function getRequestEstimates(int $request_id): array {
    static $bundle_info = NULL;
    static $stage_options = NULL;
    if ($bundle_info === NULL) {
      $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('estimate');
    }
    if ($stage_options === NULL) {
      $stage_options = [];
      $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('estimate_stage', 0, NULL, TRUE);
      foreach ($terms as $term) {
        $stage_options[] = [
          'tid' => (int) $term->id(),
          'label' => (string) $term->label(),
        ];
      }
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

      // Stage label, TID, and CSS key.
      $stage_name = '';
      $stage_key = '';
      $stage_tid = 0;
      if ($estimate->hasField('field_stage') && !$estimate->get('field_stage')->isEmpty()) {
        $stage_tid = (int) $estimate->get('field_stage')->target_id;
        $stage_term = $estimate->get('field_stage')->entity;
        if ($stage_term) {
          $stage_name = (string) $stage_term->label();
          $stage_key = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $stage_name));
          $stage_key = trim($stage_key, '-');
        }
      }

      // Total — only show if non-zero.
      $total = '';
      if ($estimate->hasField('field_estimate_total') && !$estimate->get('field_estimate_total')->isEmpty()) {
        $total_value = (float) $estimate->get('field_estimate_total')->value;
        if ($total_value > 0) {
          $total = '$' . number_format($total_value, 2);
        }
      }

      // Canonical URL.
      try {
        $url = Url::fromRoute('entity.estimate.canonical', ['estimate' => $estimate->id()])->toString();
      }
      catch (\Exception $e) {
        $url = '/estimate/' . $estimate->id();
      }

      $rows[] = [
        'id' => (int) $estimate->id(),
        'label' => (string) $label,
        'stage' => $stage_name ?: '—',
        'stage_key' => $stage_key,
        'stage_tid' => $stage_tid,
        'stage_options' => $stage_options,
        'total' => $total,
        'url' => $url,
      ];
    }

    return $rows;
  }

  /**
   * Handles quick status update from the Needs Follow-Up table.
   */
  public function statusUpdate(Request $request): RedirectResponse {
    $token = $request->request->get('token');
    if (!\Drupal::csrfToken()->validate($token, 'estimate_board_status_update')) {
      \Drupal::messenger()->addError('Invalid form token. Please try again.');
      return new RedirectResponse('/admin/office/estimates');
    }

    $request_id = (int) $request->request->get('request_id');
    $new_status = (int) $request->request->get('new_status');

    $allowed_statuses = [1652, 1653, 1654, 1655, 1656, 1657];
    if (!$request_id || !in_array($new_status, $allowed_statuses, TRUE)) {
      \Drupal::messenger()->addError('Invalid status selection.');
      return new RedirectResponse('/admin/office/estimates');
    }

    $estimate_request = \Drupal::entityTypeManager()
      ->getStorage('estimate_request')->load($request_id);
    if (!$estimate_request) {
      \Drupal::messenger()->addError('Estimate request not found.');
      return new RedirectResponse('/admin/office/estimates');
    }

    $estimate_request->set('field_status', $new_status);
    $estimate_request->save();

    $term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')->load($new_status);
    $label = $term ? $term->label() : (string) $new_status;
    \Drupal::messenger()->addStatus(
      t('Status updated to @status.', ['@status' => $label])
    );

    return new RedirectResponse('/admin/office/estimates');
  }

}
