<?php

declare(strict_types=1);

namespace Drupal\bos_edge_cache\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells LiteSpeed which anonymous responses it may serve without PHP.
 *
 * The account's binding limit is PHYSICAL MEMORY (PMEM 1 GB), not entry
 * processes: a cold render peaks at 300–460 MB, so roughly four concurrent PHP
 * requests exhaust the whole account. Entry processes never faulted during the
 * stall because the account runs out of memory long before it runs out of
 * processes. Keeping anonymous hits at the edge is therefore a memory measure,
 * not a latency one — an edge hit costs ZERO PHP.
 *
 * Drupal decides cacheability and LiteSpeed obeys. There is deliberately no URL
 * allowlist at the edge: core already knows which responses are cacheable, and a
 * second list at the edge would be a second source of truth that silently drifts.
 */
final class EdgeCacheSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountInterface $account,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    // -100: after core's FinishResponseSubscriber has set Cache-Control, since
    // the decision below READS that header rather than second-guessing it.
    return [KernelEvents::RESPONSE => ['onResponse', -100]];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $response = $event->getResponse();

    $cacheable = $this->isEdgeCacheable($request, $response);

    if ($cacheable) {
      $maxAge = (int) ($this->configFactory->get('system.performance')->get('cache.page.max_age') ?: 0);
      // Read from config rather than hardcoded, so the edge lifetime cannot
      // drift away from what Drupal tells browsers.
      if ($maxAge > 0) {
        $response->headers->set('X-LiteSpeed-Cache-Control', 'public,max-age=' . $maxAge);
      }
      else {
        // max_age 0 means Drupal is telling everyone not to cache. Obey it.
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        $cacheable = FALSE;
      }
    }
    else {
      // Explicit, not merely absent — a missing header leaves the edge to its
      // own defaults, and this module exists to be the single answer.
      $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
    }

    // ---- Purge -------------------------------------------------------------
    // Only ever on a response the edge will NOT store. If a purge rode on a
    // cacheable response, the edge would store that response WITH the purge
    // header, and every subsequent HIT would replay it — purging the cache
    // forever, on every request. That is the whole reason the flag waits.
    if (!$cacheable && $this->state->get('bos_edge_cache.purge_pending')) {
      $response->headers->set('X-LiteSpeed-Purge', '*');
      $this->state->delete('bos_edge_cache.purge_pending');
    }

    // Hard rule, asserted rather than assumed. If these two ever appear
    // together the edge cache destroys itself silently, so strip and shout.
    if ($cacheable && $response->headers->has('X-LiteSpeed-Purge')) {
      $response->headers->remove('X-LiteSpeed-Purge');
      $this->logger->error('bos_edge_cache: refused to emit X-LiteSpeed-Purge on a cacheable response for @path. This would have purged the edge on every cache hit.', [
        '@path' => $request->getPathInfo(),
      ]);
    }
  }

  /**
   * Every condition must hold. Any doubt means no edge caching.
   */
  private function isEdgeCacheable(Request $request, Response $response): bool {
    if (!in_array($request->getMethod(), ['GET', 'HEAD'], TRUE)) {
      return FALSE;
    }
    if ($response->getStatusCode() !== 200) {
      return FALSE;
    }
    if (!$this->account->isAnonymous()) {
      return FALSE;
    }
    // Core's verdict. If Drupal did not mark it public it is not ours to cache.
    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    if (!str_contains(strtolower($cacheControl), 'public')) {
      return FALSE;
    }
    // A response that sets a cookie is per-visitor by definition.
    if ($response->headers->getCookies() || $response->headers->has('Set-Cookie')) {
      return FALSE;
    }
    // A session cookie on the REQUEST means a real visitor may be mid-session
    // even if this particular response rendered anonymously.
    foreach (array_keys($request->cookies->all()) as $name) {
      if (preg_match('/^S?SESS[0-9a-f]+$/i', (string) $name)) {
        return FALSE;
      }
    }
    // The work-order intake endpoint authenticates by header, not cookie.
    if ((string) $request->headers->get('X-API-KEY', '') !== '') {
      return FALSE;
    }
    return TRUE;
  }

}
