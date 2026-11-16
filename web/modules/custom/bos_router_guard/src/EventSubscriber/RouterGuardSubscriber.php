<?php

declare(strict_types=1);

namespace Drupal\bos_router_guard\EventSubscriber;

use Drupal\bos_router_guard\RouterGuard;
use Drupal\Core\Routing\RoutingEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Wires the guard into the request lifecycle.
 *
 * - kernel.exception (first in line): a 404, or a RouteNotFoundException thrown
 *   while rendering (that is how the 404 page itself became a 500 on
 *   2026-11-14), triggers one throttled table check. Never throws.
 * - kernel.terminate: if the check asked for a rebuild, run it now that the
 *   visitor has their response.
 * - RoutingEvents::FINISHED: a complete dump just committed — record the count,
 *   then re-route any menu_link_content link core's menu rebuild (priority
 *   100, just before us) stored without its route (2026-11-16).
 */
final class RouterGuardSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouterGuard $guard,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Ahead of core's Fast404ExceptionHtmlSubscriber (200), which sets a
      // response and stops propagation for static-file 404s.
      KernelEvents::EXCEPTION => ['onException', 250],
      // After automated_cron (100), which may already have repaired the table.
      KernelEvents::TERMINATE => ['onTerminate', 90],
      RoutingEvents::FINISHED => ['onRouterFinished', 0],
    ];
  }

  public function onException(ExceptionEvent $event): void {
    $throwable = $event->getThrowable();
    if (!$throwable instanceof NotFoundHttpException && !$throwable instanceof RouteNotFoundException) {
      return;
    }
    try {
      if ($this->guard->shouldCheck()) {
        $this->guard->check('404 ' . $event->getRequest()->getPathInfo());
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Router guard check failed: @message', ['@message' => $e->getMessage()]);
    }
  }

  public function onTerminate(TerminateEvent $event): void {
    if (!$this->guard->rebuildPending()) {
      return;
    }
    try {
      $this->guard->rebuild('kernel.terminate after ' . $event->getRequest()->getPathInfo());
    }
    catch (\Throwable $e) {
      $this->logger->error('Router guard terminate rebuild failed: @message', ['@message' => $e->getMessage()]);
    }
  }

  public function onRouterFinished(): void {
    try {
      $this->guard->recordRebuilt();
    }
    catch (\Throwable $e) {
      $this->logger->error('Router guard could not record the rebuild: @message', ['@message' => $e->getMessage()]);
    }
    try {
      $this->guard->repairMenuLinks('router rebuild');
    }
    catch (\Throwable $e) {
      $this->logger->error('Router guard menu-link pass after the rebuild failed: @message', ['@message' => $e->getMessage()]);
    }
  }

}
