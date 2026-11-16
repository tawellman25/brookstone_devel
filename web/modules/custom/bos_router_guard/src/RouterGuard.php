<?php

declare(strict_types=1);

namespace Drupal\bos_router_guard;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
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
 *
 * Router fallout the guard also owns (2026-11-16): menu_link_content links
 * stored WITHOUT their route. Every `internal:` link is resolved through the
 * path validator when the menu tree is rebuilt; a path that cannot be matched
 * at that moment is written as `url = base:…` and nothing revisits it until
 * the next menu rebuild. Such a link skips its route's access check, loses
 * the active trail, and one directly under system.admin breaks core's
 * vertical toolbar for everyone (the lost expand arrows, gotcha "A menu-link
 * rebuild while the router is broken bakes `base:` into `menu_tree`…"). So
 * after every FINISHED and on cron the guard re-derives those rows and
 * re-routes the ones that resolve today; the Status row lists what is left.
 */
final class RouterGuard {

  public const STATE_EXPECTED = 'bos_router_guard.expected';
  public const STATE_EXPECTED_AT = 'bos_router_guard.expected_at';
  public const STATE_INCIDENT = 'bos_router_guard.incident';
  public const STATE_LAST_REBUILD = 'bos_router_guard.last_rebuild_request';
  public const STATE_LAST_ALERT = 'bos_router_guard.last_alert';
  public const CACHE_CHECKED = 'bos_router_guard.checked';
  public const STATE_MENU_LINKS = 'bos_router_guard.menu_links';

  private const MENU_LINK_PREFIX = 'menu_link_content:';

  /**
   * Routes every installed Drupal site has.
   *
   * Any of them missing = short router, whatever the recorded count says. This
   * covers a fresh install with no record yet.
   */
  public const SENTINELS = ['user.login', 'system.404', 'system.admin', 'entity.user.canonical'];

  private bool $rebuildPending = FALSE;

  private bool $checkedThisRequest = FALSE;

  private readonly MenuLinkManagerInterface $menuLinkManager;

  private readonly EntityTypeManagerInterface $entityTypeManager;

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
    ?MenuLinkManagerInterface $menuLinkManager = NULL,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {
    // The last two are optional so a container compiled before they were
    // added (live's, until its next rebuild) still constructs the service
    // with nine arguments — code-only deploys stay crew-hours safe.
    $this->menuLinkManager = $menuLinkManager ?? \Drupal::service('plugin.manager.menu.link');
    $this->entityTypeManager = $entityTypeManager ?? \Drupal::entityTypeManager();
  }

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
   * menu_link_content links whose stored routedness is wrong or fragile.
   *
   * Read-only. Rows stored WITHOUT a route (`url = base:…`) are re-derived
   * now, exactly as a menu rebuild would (MenuLinkContent::getPluginDefinition()
   * → path validator), and classified:
   * - 'repairable' — the path resolves to an ordinary route: the row is stale.
   * - 'role_gated' — the path is a Page Manager page with selection conditions
   *   (or several variants). Whether, and to WHICH variant, such a path
   *   resolves depends on the user running the derivation — page_manager's
   *   VariantRouteFilter evaluates the variants' conditions against the
   *   current user, and the context repository caches that user for the rest
   *   of the process — so the row must stay a plain URL, which every role
   *   follows to its own variant. Never repaired (/teammates is one: admin /
   *   teammates / supervisor variants).
   * - 'unrouted' — nothing serves the path: a dead link, or a redirect.
   * - 'failed' — could not derive; 'error' says why.
   * Rows stored ROUTED to a variant of such a page are reported as 'pinned':
   * derived in an administrator's web request, the link carries the admin
   * variant's access check and disappears for every other role; a menu
   * rebuild from drush (no user) restores the plain URL. Reported, not changed.
   *
   * @return array{repairable: array<int, array>, role_gated: array<int, array>, unrouted: array<int, array>, failed: array<int, array>, pinned: array<int, array>}
   *   Entries carry plugin_id, entity_id, menu, title, uri, url, route_name;
   *   repairable ones the re-derived route_name + definition; role_gated and
   *   pinned ones page (id) + variants (count).
   */
  public function probeMenuLinks(): array {
    $result = ['repairable' => [], 'role_gated' => [], 'unrouted' => [], 'failed' => [], 'pinned' => []];
    if (!$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return $result;
    }
    $select = 'SELECT t.id AS plugin_id, t.menu_name AS menu, t.url, t.route_name, b.id AS entity_id, m.title, m.link__uri AS uri
       FROM {menu_tree} t
       INNER JOIN {menu_link_content} b ON t.id = CONCAT(:prefix, b.uuid)
       INNER JOIN {menu_link_content_data} m ON m.id = b.id AND m.default_langcode = 1
       WHERE m.link__uri LIKE :internal AND ';
    $args = [':prefix' => self::MENU_LINK_PREFIX, ':internal' => 'internal:%'];
    $stored_unrouted = $this->database->query($select . 't.url LIKE :base ORDER BY t.menu_name, m.title', $args + [':base' => 'base:%'])->fetchAll(\PDO::FETCH_ASSOC);
    $stored_pm = $this->database->query($select . 't.route_name LIKE :pm ORDER BY t.menu_name, m.title', $args + [':pm' => 'page_manager.page_view_%'])->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($stored_pm as $row) {
      $page = $this->pageManagerPage($row['uri']);
      if ($page && $page['role_gated']) {
        $result['pinned'][] = $row + $page;
      }
    }
    if (!$stored_unrouted) {
      return $result;
    }
    $entities = $this->entityTypeManager->getStorage('menu_link_content')->loadMultiple(array_column($stored_unrouted, 'entity_id'));
    foreach ($stored_unrouted as $row) {
      $page = $this->pageManagerPage($row['uri']);
      if ($page && $page['role_gated']) {
        $result['role_gated'][] = $row + $page;
        continue;
      }
      $entity = $entities[$row['entity_id']] ?? NULL;
      if (!$entity) {
        $row['error'] = 'menu_link_content entity not loadable';
        $result['failed'][] = $row;
        continue;
      }
      try {
        $definition = $entity->getPluginDefinition();
      }
      catch (\Throwable $e) {
        $row['error'] = get_class($e) . ': ' . $e->getMessage();
        $result['failed'][] = $row;
        continue;
      }
      if (!empty($definition['route_name'])) {
        $row['route_name'] = (string) $definition['route_name'];
        $row['definition'] = $definition;
        $result['repairable'][] = $row;
      }
      else {
        $result['unrouted'][] = $row;
      }
    }
    return $result;
  }

  /**
   * The Page Manager page at a menu link's internal: path, if there is one.
   *
   * @return array{page: string, variants: int, role_gated: bool}|null
   *   role_gated: more than one variant, or any variant with selection
   *   conditions — i.e. which variant answers depends on the user.
   */
  private function pageManagerPage(string $uri): ?array {
    if (!$this->entityTypeManager->hasDefinition('page')) {
      return NULL;
    }
    $path = '/' . ltrim((string) parse_url(substr($uri, strlen('internal:')), PHP_URL_PATH), '/');
    $candidates = [$path];
    if ($this->entityTypeManager->hasDefinition('path_alias')) {
      foreach ($this->entityTypeManager->getStorage('path_alias')->loadByProperties(['alias' => $path, 'status' => 1]) as $alias) {
        $candidates[] = $alias->getPath();
      }
    }
    foreach ($this->entityTypeManager->getStorage('page')->loadByProperties(['path' => $candidates]) as $page) {
      $variants = method_exists($page, 'getVariants') ? $page->getVariants() : [];
      $conditioned = FALSE;
      foreach ($variants as $variant) {
        if (method_exists($variant, 'getSelectionConditions') && count($variant->getSelectionConditions()) > 0) {
          $conditioned = TRUE;
        }
      }
      return ['page' => (string) $page->id(), 'variants' => count($variants), 'role_gated' => $conditioned || count($variants) > 1];
    }
    return NULL;
  }

  /**
   * Re-routes every stored-unrouted menu link whose path resolves today.
   *
   * Runs at RoutingEvents::FINISHED — after core's MenuRouterRebuildSubscriber
   * (priority 100) has rebuilt the tree — and on cron, which is a separate
   * process, so a cause local to the process that rebuilt the menu is caught
   * at the next cron run. Writes through MenuLinkManager::updateDefinition(),
   * the path MenuLinkContent::postSave() takes, which invalidates
   * config:system.menu.<menu> so the toolbar hash changes and browsers
   * re-fetch their subtrees. Nothing is written when there is nothing to fix;
   * role-gated and pinned Page Manager links are never touched (see
   * probeMenuLinks()).
   *
   * @param string $trigger
   *   'router rebuild', 'cron', 'manual', 'test'.
   *
   * @return array{checked: int, repaired: array, role_gated: array, unrouted: array, failed: array, pinned: array, trigger: string}
   *   Rows as probeMenuLinks() returns them, without the definition.
   */
  public function repairMenuLinks(string $trigger): array {
    $probe = $this->probeMenuLinks();
    $summary = [
      'checked' => count($probe['repairable']) + count($probe['role_gated']) + count($probe['unrouted']) + count($probe['failed']),
      'repaired' => [],
      'role_gated' => $probe['role_gated'],
      'unrouted' => $probe['unrouted'],
      'failed' => $probe['failed'],
      'pinned' => $probe['pinned'],
      'trigger' => $trigger,
    ];
    foreach ($probe['repairable'] as $row) {
      $definition = $row['definition'];
      unset($row['definition']);
      try {
        $this->menuLinkManager->updateDefinition($row['plugin_id'], $definition, FALSE);
        $summary['repaired'][] = $row;
      }
      catch (\Throwable $e) {
        $row['error'] = get_class($e) . ': ' . $e->getMessage();
        $summary['failed'][] = $row;
      }
    }
    $label = static fn(array $r): string => sprintf('%s (%s, menu %s)', $r['title'], $r['uri'], $r['menu']);
    $previous = $this->state->get(self::STATE_MENU_LINKS) ?: [];
    $this->state->set(self::STATE_MENU_LINKS, [
      'at' => $this->time->getRequestTime(),
      'trigger' => $trigger,
      'checked' => $summary['checked'],
      'repaired' => array_map(static fn(array $r): string => $label($r) . ' → ' . $r['route_name'], $summary['repaired']),
      // Keyed by plugin id so a later pass can tell new from known.
      'role_gated' => array_combine(array_column($summary['role_gated'], 'plugin_id'), array_map(static fn(array $r): string => $label($r) . sprintf(' — page %s, %d variants', $r['page'], $r['variants']), $summary['role_gated'])),
      'unrouted' => array_combine(array_column($summary['unrouted'], 'plugin_id'), array_map($label, $summary['unrouted'])),
      'pinned' => array_combine(array_column($summary['pinned'], 'plugin_id'), array_map(static fn(array $r): string => $label($r) . ' → ' . $r['route_name'], $summary['pinned'])),
      'failed' => array_map(static fn(array $r): string => $label($r) . ': ' . $r['error'], $summary['failed']),
    ]);
    if ($summary['repaired']) {
      $this->logger->notice('Router guard re-routed @n menu link(s) that had been stored without a route (@trigger): @list', [
        '@n' => count($summary['repaired']),
        '@trigger' => $trigger,
        '@list' => implode(' · ', array_map(static fn(array $r): string => $label($r) . ' → ' . $r['route_name'], $summary['repaired'])),
      ]);
    }
    $newly_pinned = array_filter($summary['pinned'], static fn(array $r): bool => !isset($previous['pinned'][$r['plugin_id']]));
    if ($newly_pinned) {
      $this->logger->warning('Router guard: @n menu link(s) are stored pinned to ONE variant of a role-gated Page Manager page and will be hidden from the other roles (a menu rebuild from a web request does this; a rebuild from drush restores the plain URL): @list', [
        '@n' => count($newly_pinned),
        '@list' => implode(' · ', array_map(static fn(array $r): string => $label($r) . ' → ' . $r['route_name'], $newly_pinned)),
      ]);
    }
    foreach ($summary['failed'] as $row) {
      $this->logger->error('Router guard could not re-derive menu link @link: @error', [
        '@link' => $label($row),
        '@error' => $row['error'],
      ]);
    }
    return $summary;
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
