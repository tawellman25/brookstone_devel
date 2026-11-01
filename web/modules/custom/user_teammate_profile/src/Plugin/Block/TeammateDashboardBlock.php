<?php

declare(strict_types=1);

namespace Drupal\user_teammate_profile\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A teammate's own work, on their own profile page.
 *
 * ⚠ A BLOCK PLUGIN, not hook_user_view. Plugins are found by discovery, which
 * a cache rebuild performs; a brand-new HOOK on an already-enabled module is
 * not registered by `drush cr` and would force a reinstall
 * (drupal_bos_gotchas.md). Same reach, none of the risk.
 *
 * SELF-VIEW ONLY. The block is forbidden unless the profile being looked at is
 * the viewer's own. Office staff opening a teammate's profile see the ordinary
 * page — this is a tool for the person, not a readout for someone else. That is
 * a deliberate stance, not an oversight: a number a worker can see daily is
 * information; the same number on someone else's screen is surveillance, and
 * the two produce very different behaviour.
 *
 * @Block(
 *   id = "teammate_dashboard",
 *   admin_label = @Translation("Teammate dashboard (own profile)"),
 *   category = @Translation("Custom")
 * )
 */
final class TeammateDashboardBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Statuses that mean a work order is off the teammate's plate.
   *
   * Complete (1097), Warrantied (1283), Invoiced (1281), Paid (1504),
   * Canceled (1098) — the same closed set the Route Editor refuses to touch.
   */
  private const CLOSED = [1097, 1283, 1281, 1504, 1098];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly AccountInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $etm,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('database'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Only on your own profile, and only if you are crew.
   */
  protected function blockAccess(AccountInterface $account) {
    $viewed = $this->routeMatch->getParameter('user');
    $viewedId = is_object($viewed) ? (int) $viewed->id() : (int) $viewed;
    $isSelf = $viewedId > 0 && $viewedId === (int) $account->id();
    $isCrew = in_array('teammates', $account->getRoles(), TRUE);
    return AccessResult::allowedIf($isSelf && $isCrew)
      // Varies by who is looking AND whose page it is.
      ->addCacheContexts(['user', 'url.path']);
  }

  public function build(): array {
    $uid = (int) $this->currentUser->id();
    return [
      '#attached' => ['library' => ['user_teammate_profile/teammate_dashboard']],
      // Live data — a cached clock reading is worse than none.
      '#cache' => ['max-age' => 0, 'contexts' => ['user']],
      '#theme' => 'teammate_dashboard',
      '#clocked' => $this->clockedIn($uid),
      '#hours_today' => $this->hoursOnWorkOrdersToday($uid),
      '#stale_clockins' => $this->staleClockIns($uid),
      '#work' => $this->assignedWorkOldestFirst($uid),
      '#links' => [
        ['title' => 'Training', 'url' => Url::fromUserInput('/teammates/training')->toString()],
        ['title' => 'Employment', 'url' => Url::fromUserInput('/teammates/employment')->toString()],
        ['title' => 'Estimates', 'url' => Url::fromUserInput('/teammates/estimates')->toString()],
        ['title' => 'My schedule', 'url' => Url::fromUserInput('/teammates/calendar/my-schedule')->toString()],
      ],
    ];
  }

  /**
   * The punch that is open right now, if any.
   */
  private function clockedIn(int $uid): ?array {
    $q = $this->database->select('wo_time_clock_field_data', 't');
    $q->fields('t', ['id']);
    $q->join('wo_time_clock__field_teammate', 'tm', 'tm.entity_id = t.id AND tm.deleted = 0');
    $q->condition('tm.field_teammate_target_id', $uid);
    $q->leftJoin('wo_time_clock__field_end_time', 'e', 'e.entity_id = t.id AND e.deleted = 0');
    $q->isNull('e.field_end_time_value');
    $q->leftJoin('wo_time_clock__field_start_time', 's', 's.entity_id = t.id AND s.deleted = 0');
    $q->addField('s', 'field_start_time_value', 'start');
    $q->leftJoin('wo_time_clock__field_work_order', 'w', 'w.entity_id = t.id AND w.deleted = 0');
    $q->addField('w', 'field_work_order_target_id', 'wo_id');
    $q->orderBy('s.field_start_time_value', 'DESC');
    $q->range(0, 1);
    $row = $q->execute()->fetchObject();
    if (!$row || empty($row->start)) {
      return NULL;
    }
    // Stored UTC; compare in UTC and present in the site's zone.
    $started = new \DateTime($row->start . 'Z');
    $now = new \DateTime('now', new \DateTimeZone('UTC'));
    $minutes = max(0, (int) round(($now->getTimestamp() - $started->getTimestamp()) / 60));
    $wo = $row->wo_id ? $this->etm->getStorage('work_order')->load($row->wo_id) : NULL;
    $started->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    return [
      'wo_label' => $wo ? $wo->label() : 'Work order #' . (int) $row->wo_id,
      'wo_url' => $wo ? $wo->toUrl()->toString() : '',
      'since' => $started->format('g:i A'),
      'elapsed' => $this->hm($minutes),
      'long' => $minutes > 720,
    ];
  }

  /**
   * Hours logged against work orders since midnight.
   *
   * ⚠ Deliberately NOT called "billable". The punch carries no billable flag —
   * in-house tasks, warranty work and callbacks all land in the same bucket, so
   * calling this billable would overstate it and invite an argument the number
   * cannot win. It measures time on work orders, which is what it is.
   */
  private function hoursOnWorkOrdersToday(int $uid): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $midnight = new \DateTime('today midnight', $tz);
    $midnight->setTimezone(new \DateTimeZone('UTC'));
    $from = $midnight->format('Y-m-d\TH:i:s');

    $q = $this->database->select('wo_time_clock_field_data', 't');
    $q->join('wo_time_clock__field_teammate', 'tm', 'tm.entity_id = t.id AND tm.deleted = 0');
    $q->condition('tm.field_teammate_target_id', $uid);
    $q->join('wo_time_clock__field_start_time', 's', 's.entity_id = t.id AND s.deleted = 0');
    $q->condition('s.field_start_time_value', $from, '>=');
    $q->addField('s', 'field_start_time_value', 'start');
    $q->leftJoin('wo_time_clock__field_end_time', 'e', 'e.entity_id = t.id AND e.deleted = 0');
    $q->addField('e', 'field_end_time_value', 'end');

    $minutes = 0;
    $openCount = 0;
    $nowTs = (new \DateTime('now', new \DateTimeZone('UTC')))->getTimestamp();
    foreach ($q->execute() as $row) {
      $startTs = (new \DateTime($row->start . 'Z'))->getTimestamp();
      // An open punch counts up to NOW, so the figure reflects the day so far
      // rather than only the jobs already closed out.
      $endTs = !empty($row->end) ? (new \DateTime($row->end . 'Z'))->getTimestamp() : $nowTs;
      if ($openCount === 0 && empty($row->end)) {
        $openCount++;
      }
      $minutes += max(0, (int) round(($endTs - $startTs) / 60));
    }
    return ['display' => $this->hm($minutes), 'minutes' => $minutes, 'includes_open' => $openCount > 0];
  }

  /**
   * Punches left open from a previous day — the usual money leak.
   */
  private function staleClockIns(int $uid): int {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $midnight = new \DateTime('today midnight', $tz);
    $midnight->setTimezone(new \DateTimeZone('UTC'));

    $q = $this->database->select('wo_time_clock_field_data', 't');
    $q->join('wo_time_clock__field_teammate', 'tm', 'tm.entity_id = t.id AND tm.deleted = 0');
    $q->condition('tm.field_teammate_target_id', $uid);
    $q->join('wo_time_clock__field_start_time', 's', 's.entity_id = t.id AND s.deleted = 0');
    $q->condition('s.field_start_time_value', $midnight->format('Y-m-d\TH:i:s'), '<');
    $q->leftJoin('wo_time_clock__field_end_time', 'e', 'e.entity_id = t.id AND e.deleted = 0');
    $q->isNull('e.field_end_time_value');
    return (int) $q->countQuery()->execute()->fetchField();
  }

  /**
   * Every open work order scheduled to this teammate, OLDEST FIRST.
   *
   * ⚠ Assignment lives on the SCHEDULING record (`field_assigned_to`), not on
   * the work order — there is no work_order→teammate field. Ordered by the WORK
   * ORDER's created date, not the scheduled date: the whole point is first-in
   * first-out, so a job raised in August outranks one raised last week whatever
   * day each happens to sit on.
   */
  private function assignedWorkOldestFirst(int $uid, int $limit = 25): array {
    $q = $this->database->select('scheduling__field_assigned_to', 'a');
    $q->condition('a.deleted', 0);
    $q->condition('a.field_assigned_to_target_id', $uid);
    $q->join('scheduling__field_work_order', 'swo', 'swo.entity_id = a.entity_id AND swo.deleted = 0');
    $q->join('work_order_field_data', 'wo', 'wo.id = swo.field_work_order_target_id');
    $q->leftJoin('work_order__field_status', 'st', 'st.entity_id = wo.id AND st.deleted = 0');
    $q->condition('st.field_status_target_id', self::CLOSED, 'NOT IN');
    $q->addField('wo', 'id', 'wo_id');
    $q->addField('wo', 'created', 'created');
    $q->leftJoin('work_order__field_property', 'wp', 'wp.entity_id = wo.id AND wp.deleted = 0');
    $q->leftJoin('properties__field_nickname', 'nick', 'nick.entity_id = wp.field_property_target_id AND nick.deleted = 0');
    $q->addField('nick', 'field_nickname_value', 'nickname');
    $q->distinct();
    $q->orderBy('wo.created', 'ASC');
    $q->range(0, $limit);

    $out = [];
    $now = \Drupal::time()->getRequestTime();
    foreach ($q->execute() as $row) {
      $wo = $this->etm->getStorage('work_order')->load($row->wo_id);
      if (!$wo) {
        continue;
      }
      $days = (int) floor(($now - (int) $row->created) / 86400);
      $out[] = [
        'label' => $wo->label(),
        'url' => $wo->toUrl()->toString(),
        'where' => trim((string) ($row->nickname ?? '')),
        'days' => $days,
        // ⚠ 90 days, not 30. Measured on live 2026-11-01: of 107 open assigned
        // work orders only 17 are under two weeks old and 70 are over 30 days,
        // so a 30-day threshold paints nearly the whole list red and the marker
        // stops meaning anything. At 90 it flags the 29 that are genuinely
        // wrong rather than the normal backlog.
        'old' => $days >= 90,
      ];
    }
    return $out;
  }

  /**
   * Minutes as "6h 20m" — readable at a glance on a phone.
   */
  private function hm(int $minutes): string {
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm', $m);
  }

}
