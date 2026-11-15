<?php

declare(strict_types=1);

namespace Drupal\bos_router_guard;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Detects a half-written router table and repairs it.
 *
 * Drupal rebuilds the router by DELETE + chunked INSERTs inside one
 * transaction. An exception rolls that back, but a request the web server
 * TERMINATES never throws: lsphp's clean shutdown runs destructors and
 * Transaction::__destruct() COMMITS whatever was written. Live was down
 * 03:35–06:40 MT on 2026-11-14 with 14,300 of 22,056 routes and nothing noticed
 * (gotcha "`/core/install.php` on an installed site rebuilds the whole router…").
 *
 * The dumper is the only writer of the table and every complete dump ends with
 * RoutingEvents::FINISHED, so a row count BELOW the one recorded at the last
 * FINISHED — or a core route every installed site has being absent — is the
 * signature of a dump that died, and of nothing else. Extra rows cannot appear
 * without a dump; a count ABOVE the record only means the process died between
 * the commit and FINISHED, and the record is adopted silently.
 *
 * What happens on a short table: an incident is opened in State, the error is
 * logged, the alert address is emailed (at most once per alert_interval) and a
 * rebuild is requested (at most once per rebuild_interval) — in this process
 * for cron/CLI, at kernel.terminate for a web request so the visitor gets the
 * 404 page first. FINISHED then records the new count, closes the incident,
 * logs the repair and, if an alert went out, emails the all-clear.
 */
final class RouterGuard {

  public const STATE_EXPECTED = 'bos_router_guard.expected';
  public const STATE_EXPECTED_AT = 'bos_router_guard.expected_at';
  public const STATE_INCIDENT = 'bos_router_guard.incident';
  public const STATE_LAST_REBUILD = 'bos_router_guard.last_rebuild_request';
  public const STATE_LAST_ALERT = 'bos_router_guard.last_alert';
  public const CACHE_CHECKED = 'bos_router_guard.checked';

  /**
   * Routes every installed Drupal site has.
   *
   * Any of them missing = short router, whatever the recorded count says. This
   * covers a fresh install with no record yet.
   */
  public const SENTINELS = ['user.login', 'system.404', 'system.admin', 'entity.user.canonical'];

  private bool $rebuildPending = FALSE;

  private bool $checkedThisRequest = FALSE;

  public function __construct(
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteBuilderInterface $routeBuilder,
    private readonly MailManagerInterface $mailManager,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Rows in the router table right now.
   */
  public function count(): int {
    return (int) $this->database->query('SELECT COUNT(*) FROM {router}')->fetchField();
  }

  /**
   * Rows recorded at the last complete rebuild, or NULL before the first one.
   */
  public function expected(): ?int {
    $v = $this->state->get(self::STATE_EXPECTED);
    return $v === NULL ? NULL : (int) $v;
  }

  /**
   * Sentinel routes absent from the table.
   */
  public function missingSentinels(): array {
    $present = $this->database
      ->query('SELECT name FROM {router} WHERE name IN (:names[])', [':names[]' => self::SENTINELS])
      ->fetchCol();
    return array_values(array_diff(self::SENTINELS, $present));
  }

  /**
   * Read-only picture of the table (Status report, verify script).
   */
  public function inspect(): array {
    $count = $this->count();
    $expected = $this->expected();
    $missing = $this->missingSentinels();
    return [
      'count' => $count,
      'expected' => $expected,
      'expected_at' => $this->state->get(self::STATE_EXPECTED_AT),
      'missing' => $missing,
      'short' => $missing !== [] || ($expected !== NULL && $count < $expected),
      'incident' => $this->state->get(self::STATE_INCIDENT),
    ];
  }

  /**
   * Whether a 404-driven check should run now.
   *
   * Once per request and at most once a minute per site, so a scanner's 404
   * storm costs one COUNT query, not one per hit.
   */
  public function shouldCheck(): bool {
    if ($this->checkedThisRequest) {
      return FALSE;
    }
    $this->checkedThisRequest = TRUE;
    if ($this->cache->get(self::CACHE_CHECKED)) {
      return FALSE;
    }
    $this->cache->set(self::CACHE_CHECKED, 1, $this->time->getRequestTime() + 60);
    return TRUE;
  }

  /**
   * Checks the table and, if it is short, alerts and requests a rebuild.
   *
   * @param string $trigger
   *   Where the check came from: '404 /path', 'cron', 'test'.
   * @param bool $rebuild_now
   *   TRUE to rebuild in this process (cron, CLI); FALSE to rebuild at
   *   kernel.terminate so the current visitor is answered first.
   *
   * @return array
   *   inspect() plus 'trigger', 'rebuild' (none|now|at_terminate|throttled) and
   *   'alerted'.
   */
  public function check(string $trigger, bool $rebuild_now = FALSE): array {
    $facts = $this->inspect();
    $facts['trigger'] = $trigger;
    $facts['rebuild'] = 'none';
    $facts['alerted'] = FALSE;
    $now = $this->time->getRequestTime();

    if (!$facts['short']) {
      if ($facts['expected'] === NULL || $facts['count'] > $facts['expected']) {
        // No record yet, or a dump committed and its process died before
        // FINISHED. Reality wins.
        $this->record($facts['count']);
        $this->logger->info('Router guard adopted @count routes as the expected count (was @was) — trigger: @trigger.', [
          '@count' => $facts['count'],
          '@was' => $facts['expected'] ?? 'none',
          '@trigger' => $trigger,
        ]);
      }
      return $facts;
    }

    $incident = $facts['incident'] ?: [
      'opened' => $now,
      'trigger' => $trigger,
      'count' => $facts['count'],
      'expected' => $facts['expected'],
      'missing' => $facts['missing'],
      'alerts' => 0,
      'rebuilds' => 0,
    ];
    $incident['last_seen'] = $now;
    $incident['last_trigger'] = $trigger;
    $this->logger->error('Router table SHORT: @count of @expected routes, core routes missing: @missing — trigger: @trigger.', [
      '@count' => $facts['count'],
      '@expected' => $facts['expected'] ?? '?',
      '@missing' => $facts['missing'] ? implode(', ', $facts['missing']) : 'none',
      '@trigger' => $trigger,
    ]);

    $settings = $this->configFactory->get('bos_router_guard.settings');
    $last_rebuild = (int) $this->state->get(self::STATE_LAST_REBUILD, 0);
    if ($now - $last_rebuild >= (int) $settings->get('rebuild_interval')) {
      $this->state->set(self::STATE_LAST_REBUILD, $now);
      $incident['rebuilds']++;
      if ($rebuild_now) {
        $facts['rebuild'] = 'now';
      }
      else {
        $this->rebuildPending = TRUE;
        $facts['rebuild'] = 'at_terminate';
      }
    }
    else {
      $facts['rebuild'] = 'throttled';
    }

    $last_alert = (int) $this->state->get(self::STATE_LAST_ALERT, 0);
    if ($now - $last_alert >= (int) $settings->get('alert_interval')) {
      $this->state->set(self::STATE_LAST_ALERT, $now);
      $facts['alerted'] = $this->mail('short', $facts + ['incident_opened' => $incident['opened']]);
      if ($facts['alerted']) {
        $incident['alerts']++;
      }
    }
    // Saved before any rebuild so FINISHED finds the open incident.
    $this->state->set(self::STATE_INCIDENT, $incident);

    if ($facts['rebuild'] === 'now') {
      $this->rebuild($trigger);
    }
    return $facts;
  }

  /**
   * TRUE when check() asked for a rebuild at kernel.terminate.
   */
  public function rebuildPending(): bool {
    return $this->rebuildPending;
  }

  /**
   * Rebuilds the router under core's router_rebuild lock.
   *
   * Limits: a PHP fatal (memory or time) is a shutdown, and a shutdown COMMITS
   * the half-written table — that is the whole bug — whereas the LVE OOM kill
   * on live is a SIGKILL, which rolls back. So inside the rebuild we prefer the
   * kernel's limit to PHP's: no PHP memory limit, a generous time limit. (Dev
   * measurement 2026-11-15: a router-only rebuild is 6.6 s and peaks at 272 MB.)
   *
   * @return bool
   *   TRUE when this process rebuilt the table; FALSE when another process held
   *   the lock and did it, when the table was already whole, or on failure.
   */
  public function rebuild(string $trigger): bool {
    $this->rebuildPending = FALSE;
    $before = $this->inspect();
    if (!$before['short']) {
      $this->logger->notice('Router guard: rebuild requested (@trigger) but the table is whole again (@count routes) — nothing to do.', [
        '@trigger' => $trigger,
        '@count' => $before['count'],
      ]);
      return FALSE;
    }
    @set_time_limit(300);
    if (PHP_SAPI !== 'cli') {
      @ini_set('memory_limit', '-1');
    }
    $t0 = microtime(TRUE);
    $this->logger->notice('Router guard rebuilding the router (@trigger): @count of @expected routes present.', [
      '@trigger' => $trigger,
      '@count' => $before['count'],
      '@expected' => $before['expected'] ?? '?',
    ]);
    try {
      $rebuilt = $this->routeBuilder->rebuild();
    }
    catch (\Throwable $e) {
      $this->logger->error('Router guard rebuild FAILED: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
    $this->logger->notice('Router guard rebuild @result in @seconds s — @count routes now.', [
      '@result' => $rebuilt ? 'done' : 'done by another process (lock held)',
      '@seconds' => sprintf('%.1f', microtime(TRUE) - $t0),
      '@count' => $this->count(),
    ]);
    return (bool) $rebuilt;
  }

  /**
   * RoutingEvents::FINISHED — a complete dump just committed.
   */
  public function recordRebuilt(): void {
    $count = $this->count();
    $this->record($count);
    $incident = $this->state->get(self::STATE_INCIDENT);
    if (!$incident) {
      return;
    }
    $this->state->delete(self::STATE_INCIDENT);
    $this->logger->notice('Router REPAIRED: @count routes (incident opened @opened by @trigger with @was routes; @rebuilds rebuild request(s), @alerts alert(s)).', [
      '@count' => $count,
      '@opened' => date('Y-m-d H:i:s T', (int) $incident['opened']),
      '@trigger' => $incident['trigger'] ?? '?',
      '@was' => $incident['count'] ?? '?',
      '@rebuilds' => $incident['rebuilds'] ?? 0,
      '@alerts' => $incident['alerts'] ?? 0,
    ]);
    if (!empty($incident['alerts'])) {
      $this->mail('repaired', ['count' => $count, 'incident' => $incident]);
    }
  }

  /**
   * Where alerts go: settings alert_email, else the site mail.
   */
  public function recipient(): string {
    $to = trim((string) $this->configFactory->get('bos_router_guard.settings')->get('alert_email'));
    return $to !== '' ? $to : (string) $this->configFactory->get('system.site')->get('mail');
  }

  /**
   * The host name for subjects and links.
   */
  public function host(): string {
    $request = $this->requestStack->getCurrentRequest();
    $host = $request ? $request->getHost() : '';
    return ($host !== '' && $host !== 'default') ? $host : (string) php_uname('n');
  }

  private function record(int $count): void {
    $this->state->setMultiple([
      self::STATE_EXPECTED => $count,
      self::STATE_EXPECTED_AT => $this->time->getRequestTime(),
    ]);
  }

  private function mail(string $key, array $facts): bool {
    $to = $this->recipient();
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === FALSE) {
      $this->logger->warning('Router guard has no valid alert address (@to) — nothing emailed.', ['@to' => $to]);
      return FALSE;
    }
    try {
      $result = $this->mailManager->mail('bos_router_guard', $key, $to, 'en', ['facts' => $facts, 'host' => $this->host()]);
      return !empty($result['result']);
    }
    catch (\Throwable $e) {
      $this->logger->error('Router guard email failed: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

}
