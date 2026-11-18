<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\wo_clock\Service\WoClockService;

/**
 * "Who is clocked in right now" — one place, reused everywhere.
 *
 * Rows come from WoClockService::getOpenEntries(): every wo_time_clock entry
 * with field_start_time set and field_end_time EMPTY, unbounded (no row limit,
 * no date floor), so a clock-in forgotten weeks ago is still here. Consumers:
 *   - ActiveNowController (roster-wide board, with a summary line),
 *   - buildStatusSection() — the "Currently clocked into" card on a teammate's
 *     user page (hook_user_view) and on the per-teammate variance detail page.
 *
 * Stale = a likely missed clock-out: the entry started on a PRIOR calendar
 * day (site timezone) or more than STALE_HOURS ago. It is flagged, never
 * hidden — surfacing those entries is half the point.
 *
 * Read-only. Nothing here changes clock data.
 */
final class OpenPunchService {

  /**
   * Open longer than this (hours) = possible missed clock-out.
   */
  public const STALE_HOURS = 14;

  /**
   * Stale reasons.
   */
  public const STALE_PRIOR_DAY = 'prior_day';
  public const STALE_LONG = 'long';

  public function __construct(
    private readonly EntityTypeManagerInterface $em,
    private readonly WoClockService $clock,
    private readonly DateFormatterInterface $dateFmt,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $config,
  ) {}

  /**
   * One row per OPEN entry, oldest first.
   *
   * @param int|null $uid
   *   NULL = everyone; a uid = that teammate only (field_teammate or owner).
   *
   * @return array<int, array<string, mixed>>
   *   Keys: entry_id, uid, teammate_label, teammate_url, wo_id, wo_label,
   *   wo_url, start_iso, start_ts, elapsed (seconds), elapsed_text, since
   *   (MM/DD/YYYY h:i AM/PM), since_date (MM/DD/YYYY), stale (bool),
   *   stale_reason (prior_day | long | NULL).
   */
  public function rows(?int $uid = NULL): array {
    $now = $this->time->getRequestTime();
    $rows = [];
    foreach ($this->clock->getOpenEntries($uid) as $entry) {
      $teammate = $this->resolveTeammate($entry);
      if (!$teammate) {
        // No resolvable person: nothing to attribute the time to. Same rule
        // the Active Now board has always applied.
        continue;
      }
      $startIso = (string) ($entry->get('field_start_time')->value ?? '');
      $startTs = $startIso !== '' ? (int) strtotime($startIso . 'Z') : 0;
      $elapsed = $startTs > 0 ? max(0, $now - $startTs) : 0;
      $staleReason = $startTs > 0 ? $this->staleReason($startTs, $now) : NULL;

      $woId = 0;
      $woLabel = '—';
      $woUrl = NULL;
      if ($entry->hasField('field_work_order') && !$entry->get('field_work_order')->isEmpty()) {
        $woId = (int) $entry->get('field_work_order')->target_id;
        $wo = $entry->get('field_work_order')->entity;
        $woLabel = $wo ? ((string) $wo->label() ?: 'WO #' . $woId) : 'WO #' . $woId . ' (missing)';
        $woUrl = Url::fromUri('internal:/work_order/' . $woId)->toString();
      }

      $rows[] = [
        'entry_id' => (int) $entry->id(),
        'uid' => (int) $teammate->id(),
        'teammate_label' => $teammate->getDisplayName(),
        'teammate_url' => $teammate->toUrl()->toString(),
        'wo_id' => $woId,
        'wo_label' => $woLabel,
        'wo_url' => $woUrl,
        'start_iso' => $startIso,
        'start_ts' => $startTs,
        'elapsed' => $elapsed,
        'elapsed_text' => $startTs > 0 ? $this->formatElapsed($elapsed) : '—',
        'since' => $startTs > 0 ? $this->dateFmt->format($startTs, 'custom', 'm/d/Y g:i A') : '—',
        'since_date' => $startTs > 0 ? $this->dateFmt->format($startTs, 'custom', 'm/d/Y') : '—',
        'stale' => $staleReason !== NULL,
        'stale_reason' => $staleReason,
      ];
    }
    return $rows;
  }

  /**
   * Counts for a summary line: entries, distinct teammates, active, stale,
   * and teammates holding more than one open entry (itself a warning sign).
   *
   * @return array{entries: int, teammates: int, active: int, stale: int, multi: int}
   */
  public function summary(array $rows): array {
    $perUid = [];
    $stale = 0;
    foreach ($rows as $r) {
      $perUid[$r['uid']] = ($perUid[$r['uid']] ?? 0) + 1;
      if ($r['stale']) {
        $stale++;
      }
    }
    return [
      'entries' => count($rows),
      'teammates' => count($perUid),
      'active' => count($rows) - $stale,
      'stale' => $stale,
      'multi' => count(array_filter($perUid, static fn(int $n) => $n > 1)),
    ];
  }

  /**
   * Why an open entry counts as stale, or NULL when it is a live shift.
   *
   * Prior calendar day is judged in the site's default timezone, not UTC: a
   * clock-in at 11 PM Mountain is "today" until midnight Mountain.
   */
  public function staleReason(int $startTs, ?int $now = NULL): ?string {
    $now ??= $this->time->getRequestTime();
    $tz = new \DateTimeZone((string) ($this->config->get('system.date')->get('timezone.default') ?: date_default_timezone_get()));
    $startDay = (new \DateTimeImmutable('@' . $startTs))->setTimezone($tz)->format('Y-m-d');
    $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d');
    if ($startDay < $today) {
      return self::STALE_PRIOR_DAY;
    }
    if ($now - $startTs > self::STALE_HOURS * 3600) {
      return self::STALE_LONG;
    }
    return NULL;
  }

  /**
   * "N min" · "X hr Y min" · "D d X hr" (two days or more — "672 hr" reads
   * as nothing; "28 d 3 hr" reads as the problem it is).
   */
  public function formatElapsed(int $seconds): string {
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $hrs = intdiv($seconds % 86400, 3600);
    $mins = intdiv($seconds % 3600, 60);
    if ($days >= 2) {
      return $days . ' d ' . $hrs . ' hr';
    }
    $hrs += $days * 24;
    if ($hrs === 0) {
      return $mins . ' min';
    }
    return $hrs . ' hr ' . $mins . ' min';
  }

  /**
   * The "Currently clocked into" card for one teammate.
   *
   * Lists every open entry — "Clocked in: WO <id> — <title> (linked) — since
   * <when>, elapsed <duration>", stale ones flagged "⚠ open since <date> —
   * possible missed clock-out" — or "Not clocked in." Uncached (the elapsed
   * time moves), tagged so a clock-in/out elsewhere also invalidates it.
   */
  public function buildStatusSection(int $uid, bool $withHeading = TRUE): array {
    $rows = $this->rows($uid);
    $anyStale = (bool) array_filter($rows, static fn(array $r) => $r['stale']);
    $class = ['bos-clock-status', $rows ? ($anyStale ? 'bos-clock-status--stale' : 'bos-clock-status--open') : 'bos-clock-status--none'];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => $class],
      '#attached' => ['library' => ['bos_teammate_operations/variance_dashboard']],
      '#cache' => ['max-age' => 0, 'tags' => ['wo_time_clock_list']],
    ];
    if ($withHeading) {
      $build['heading'] = ['#markup' => '<h3>' . t('Currently clocked into') . '</h3>', '#allowed_tags' => ['h3']];
    }
    if (!$rows) {
      $build['none'] = ['#markup' => '<p class="bos-clock-none">' . t('Not clocked in.') . '</p>', '#allowed_tags' => ['p']];
      return $build;
    }
    $items = [];
    foreach ($rows as $r) {
      $wo = $r['wo_url']
        ? '<a href="' . htmlspecialchars($r['wo_url']) . '">WO ' . $r['wo_id'] . ' — ' . htmlspecialchars($r['wo_label']) . '</a>'
        : '<em>' . t('no work order on the entry') . '</em>';
      $text = t('Clocked in:') . ' ' . $wo . ' — ' . t('since') . ' ' . htmlspecialchars($r['since'])
        . ', ' . t('elapsed') . ' ' . htmlspecialchars($r['elapsed_text']);
      if ($r['stale']) {
        $text .= ' <span class="bos-clock-stale-flag">⚠ ' . t('open since @date — possible missed clock-out', ['@date' => $r['since_date']]) . '</span>';
      }
      $items[] = [
        '#markup' => $text,
        '#allowed_tags' => ['a', 'em', 'span'],
        '#wrapper_attributes' => ['class' => $r['stale'] ? ['bos-clock-stale'] : ['bos-clock-open']],
      ];
    }
    $build['list'] = ['#theme' => 'item_list', '#items' => $items];
    return $build;
  }

  /**
   * field_teammate first (the roster reference), owner uid as the legacy
   * fallback — the resolution order the Active Now board has always used.
   */
  private function resolveTeammate(EntityInterface $entry): ?UserInterface {
    if ($entry->hasField('field_teammate') && !$entry->get('field_teammate')->isEmpty()) {
      $u = $entry->get('field_teammate')->entity;
      if ($u instanceof UserInterface) {
        return $u;
      }
    }
    $ownerId = method_exists($entry, 'getOwnerId') ? (int) ($entry->getOwnerId() ?? 0) : 0;
    if ($ownerId > 0) {
      $u = $this->em->getStorage('user')->load($ownerId);
      if ($u instanceof UserInterface) {
        return $u;
      }
    }
    return NULL;
  }

}
