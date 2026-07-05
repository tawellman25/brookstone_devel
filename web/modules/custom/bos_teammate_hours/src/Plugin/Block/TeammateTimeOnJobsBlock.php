<?php

namespace Drupal\bos_teammate_hours\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * "Time on Jobs" — the viewing teammate's own clocked WO hours.
 *
 * Self-only by construction: it always reads the CURRENT user's
 * `wo_time_clock` entries for the selected calendar week (Sunday–Saturday).
 * Deliberately shows NO GPS location/distance and NO dollar figures — just
 * hours on jobs, grouped by day, with a week total.
 *
 * @Block(
 *   id = "teammate_time_on_jobs",
 *   admin_label = @Translation("Teammate — Time on Jobs (my clocked hours)"),
 *   category = @Translation("BOS Teammates"),
 * )
 */
class TeammateTimeOnJobsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $uid = (int) $this->currentUser->id();

    // Which week? `?week=-1` = last week, `?week=1` = next week, default 0.
    $request = $this->requestStack->getCurrentRequest();
    $offset = (int) ($request ? $request->query->get('week', 0) : 0);

    $tz = new \DateTimeZone(date_default_timezone_get());
    $utc = new \DateTimeZone('UTC');

    // Sunday 00:00 of the selected week, in the site timezone.
    $anchor = new \DateTime('now', $tz);
    $anchor->modify(($offset * 7) . ' days');
    $anchor->modify('-' . (int) $anchor->format('w') . ' days');
    $weekStart = new \DateTime($anchor->format('Y-m-d') . ' 00:00:00', $tz);
    $weekEnd = clone $weekStart;
    $weekEnd->modify('+7 days');

    // The datetime field stores UTC 'Y-m-d\TH:i:s'; convert the bounds.
    $startUtc = (clone $weekStart)->setTimezone($utc)->format('Y-m-d\TH:i:s');
    $endUtc = (clone $weekEnd)->setTimezone($utc)->format('Y-m-d\TH:i:s');

    $storage = $this->entityTypeManager->getStorage('wo_time_clock');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'entry')
      ->condition('field_teammate', $uid)
      ->condition('field_start_time', $startUtc, '>=')
      ->condition('field_start_time', $endUtc, '<')
      ->sort('field_start_time', 'ASC')
      ->execute();

    $days = [];
    $week_total = 0.0;
    foreach ($storage->loadMultiple($ids) as $entry) {
      $start_raw = $entry->get('field_start_time')->value;
      if (!$start_raw) {
        continue;
      }
      $start = $this->toLocal($start_raw, $tz);
      $day_key = $start->format('Y-m-d');
      if (!isset($days[$day_key])) {
        $days[$day_key] = [
          'date_label' => $start->format('D m/d/Y'),
          'entries' => [],
          'total' => 0.0,
          'has_open' => FALSE,
        ];
      }

      $end_raw = $entry->get('field_end_time')->value;
      $open = empty($end_raw);
      $duration = (float) $entry->get('field_total_time')->value;

      [$wo_id, $wo_url, $property] = $this->jobInfo($entry);

      $row = [
        'wo_id' => $wo_id,
        'wo_url' => $wo_url,
        'property' => $property,
        'start' => $start->format('g:i A'),
        'end' => $open ? '' : $this->toLocal($end_raw, $tz)->format('g:i A'),
        'duration' => $open ? '' : rtrim(rtrim(number_format($duration, 2), '0'), '.'),
        'open' => $open,
      ];
      $days[$day_key]['entries'][] = $row;

      if ($open) {
        $days[$day_key]['has_open'] = TRUE;
      }
      else {
        $days[$day_key]['total'] += $duration;
        $week_total += $duration;
      }
    }

    // Round the per-day totals for display.
    foreach ($days as &$day) {
      $day['total'] = rtrim(rtrim(number_format($day['total'], 2), '0'), '.');
    }
    unset($day);

    $label_end = (clone $weekEnd)->modify('-1 day');
    $week_label = $weekStart->format('m/d/Y') . ' – ' . $label_end->format('m/d/Y');

    return [
      '#theme' => 'teammate_time_on_jobs',
      '#days' => array_values($days),
      '#week_total' => rtrim(rtrim(number_format($week_total, 2), '0'), '.'),
      '#week_label' => $week_label,
      '#is_current_week' => ($offset === 0),
      '#prev_url' => '?week=' . ($offset - 1),
      '#next_url' => ($offset < 0) ? '?week=' . ($offset + 1) : NULL,
      '#today_url' => ($offset !== 0) ? '?week=0' : NULL,
      '#attached' => ['library' => ['bos_teammate_hours/time_on_jobs']],
      '#cache' => [
        'contexts' => ['user', 'url.query_args:week'],
        'tags' => ['wo_time_clock_list'],
        // Totals shift as the day progresses / entries close; keep it short.
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Convert a stored UTC datetime string to a local DrupalDateTime.
   */
  protected function toLocal($raw, \DateTimeZone $tz) {
    $dt = new DrupalDateTime($raw, 'UTC');
    $dt->setTimezone($tz);
    return $dt;
  }

  /**
   * Resolve the WO id, URL, and property label for a time-clock entry.
   *
   * @return array
   *   [wo_id, wo_url|null, property_label|null]
   */
  protected function jobInfo($entry) {
    $wo = $entry->get('field_work_order')->entity;
    if (!$wo) {
      return [NULL, NULL, NULL];
    }
    $wo_id = $wo->id();
    $wo_url = NULL;
    try {
      $wo_url = $wo->toUrl()->toString();
    }
    catch (\Exception $e) {
      // No canonical route available; leave the id unlinked.
    }

    $property = NULL;
    if ($wo->hasField('field_property') && ($prop = $wo->get('field_property')->entity)) {
      if ($prop->hasField('field_nickname') && !$prop->get('field_nickname')->isEmpty()) {
        $property = $prop->get('field_nickname')->value;
      }
      elseif ($prop->hasField('field_full_address') && !$prop->get('field_full_address')->isEmpty()) {
        $property = $prop->get('field_full_address')->value;
      }
      else {
        $property = $prop->label();
      }
    }

    return [$wo_id, $wo_url, $property];
  }

  /**
   * {@inheritdoc}
   *
   * Only makes sense for an authenticated user viewing their own page.
   */
  protected function blockAccess(AccountInterface $account) {
    return \Drupal\Core\Access\AccessResult::allowedIf($account->isAuthenticated())
      ->addCacheContexts(['user.roles:authenticated']);
  }

}
