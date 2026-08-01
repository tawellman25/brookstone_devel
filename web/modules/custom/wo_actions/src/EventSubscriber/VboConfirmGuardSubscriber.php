<?php

declare(strict_types=1);

namespace Drupal\wo_actions\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects the VBO confirm page to the list when the selection is empty.
 *
 * Views Bulk Operations' own confirm form crashes with a fatal TypeError when
 * it is loaded with no live selection: ConfirmAction::getFormData() reads a
 * NULL tempstore and passes it to addListData(array $form_data), which throws
 * "Argument #1 ($form_data) must be of type array, null given". The office hit
 * this white-screen on 2026-07-31 by loading the mow-crew billing confirm URL
 * after their batch had already run (browser Back to the confirm page, or a
 * refresh) — the tempstore had been cleared, so the page fatally errored even
 * though nothing was actually wrong (their invoices had gone through).
 *
 * The crash is inside contrib code that runs during form BUILD, before any
 * hook_form_alter, so it cannot be caught there. This subscriber runs on
 * kernel.request AFTER routing (so the view_id/display_id route params are
 * populated) and, for the confirm route only, checks the user's live VBO
 * tempstore. An empty/expired selection is redirected back to the view with a
 * friendly message instead of the fatal error. A real selection is left
 * untouched so normal confirm/execute proceeds. Companion to the stale-selection
 * replay guard in wo_actions.module (which protects the submit side).
 */
final class VboConfirmGuardSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The VBO confirm route (path: /views-bulk-operations/confirm/{view}/{disp}).
   */
  private const CONFIRM_ROUTE = 'views_bulk_operations.confirm';

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 30: after Symfony's RouterListener (32) has set _route and the
    // route params, but before the controller/form is resolved and built.
    return [KernelEvents::REQUEST => [['onRequest', 30]]];
  }

  /**
   * Redirect the confirm page to its view when the selection is empty.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== self::CONFIRM_ROUTE) {
      return;
    }

    $view_id = $request->attributes->get('view_id');
    $display_id = $request->attributes->get('display_id');
    if (!$view_id || !$display_id) {
      return;
    }

    $data = $this->tempStoreFactory
      ->get('views_bulk_operations_' . $view_id . '_' . $display_id)
      ->get((string) $this->currentUser->id());

    // A live selection — let VBO build and run the confirm form normally.
    if (!empty($data)) {
      return;
    }

    // Empty/expired selection — redirect to the list instead of the fatal
    // TypeError. This is the ONLY thing this subscriber does.
    $this->messenger->addWarning($this->t('Your selection has expired or was already submitted — please select the work orders again.'));
    $event->setResponse(new RedirectResponse($this->resolveListUrl($view_id, $display_id)));
  }

  /**
   * The view page URL for this display, falling back to the front page.
   */
  private function resolveListUrl(string $view_id, string $display_id): string {
    try {
      $url = Url::fromRoute("view.$view_id.$display_id");
      if ($url->access($this->currentUser)) {
        return $url->toString();
      }
    }
    catch (\Throwable $e) {
      // No page route for this display — fall through to the front page.
    }
    return Url::fromRoute('<front>')->toString();
  }

}
