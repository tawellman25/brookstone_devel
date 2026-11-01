<?php

declare(strict_types=1);

namespace Drupal\bos_access_denied\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The 403 page.
 *
 * Core's default is "You are not authorized to access this page." and a dead
 * end — no way back, no way in. Two different people hit it for two different
 * reasons, so it answers two different questions:
 *
 *   - A CREW MEMBER whose session has quietly expired, clicking a bookmark.
 *     They are anonymous by then, so they are not told "you lack permission" —
 *     they are offered a sign-in that returns them to the page they wanted.
 *   - A VISITOR who found an internal URL. They get something worth looking
 *     at: real service photos and the ways to get hold of the company.
 *
 * ⚠ The denied path comes from the MAIN request, not the current one. Core
 * serves a custom 403 as a SUBREQUEST (CustomPageExceptionHtmlSubscriber), so
 * \Drupal::request() here is the subrequest to /access-denied and would send
 * everyone back to the error page after login. ?destination is the fallback.
 */
final class AccessDeniedController extends ControllerBase {

  /**
   * Path prefixes that mean "this was a working page, not a public one".
   *
   * Used only to choose the WORDING. Access is never decided here.
   */
  private const INTERNAL_PREFIXES = [
    '/admin', '/teammates', '/work_order', '/wo_', '/node/add',
    '/properties', '/contracts', '/estimate', '/equipment', '/material',
  ];

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly AccountInterface $account,
    private readonly EntityTypeManagerInterface $etm,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  public function page(): array {
    $denied = $this->deniedPath();
    $internal = $this->looksInternal($denied);
    $build = [
      '#attached' => ['library' => ['bos_access_denied/access_denied']],
      // Never cache: the content depends on who is asking and where they were.
      '#cache' => ['max-age' => 0],
    ];

    if ($this->account->isAuthenticated()) {
      $build['content'] = $this->signedInButBlocked($denied);
    }
    elseif ($internal) {
      $build['content'] = $this->sessionProbablyExpired($denied);
    }
    else {
      $build['content'] = $this->publicWelcome();
    }
    return $build;
  }

  /**
   * The path the visitor actually asked for.
   */
  private function deniedPath(): string {
    $main = $this->requestStack->getMainRequest();
    $current = $this->requestStack->getCurrentRequest();
    $path = $main ? $main->getPathInfo() : '/';
    // If this was reached directly rather than as a 403 subrequest, the main
    // request IS /access-denied; fall back to ?destination, then to nothing.
    if ($path === '' || str_starts_with($path, '/access-denied')) {
      $path = (string) ($current?->query->get('destination') ?? '');
    }
    $path = '/' . ltrim(trim($path), '/');
    // Only ever hand an internal path to the login link.
    if ($path === '/' || str_contains($path, '://') || str_starts_with($path, '//')) {
      return '';
    }
    return $path;
  }

  private function looksInternal(string $path): bool {
    foreach (self::INTERNAL_PREFIXES as $prefix) {
      if (str_starts_with($path, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Anonymous, on a working page — almost always an expired session.
   */
  private function sessionProbablyExpired(string $denied): array {
    $options = $denied !== '' ? ['query' => ['destination' => $denied]] : [];
    return [
      '#theme' => 'bos_access_denied_signin',
      '#denied_path' => $denied,
      '#login_url' => Url::fromRoute('user.login', [], $options)->toString(),
      '#reset_url' => Url::fromRoute('user.pass')->toString(),
      '#home_url' => Url::fromRoute('<front>')->toString(),
    ];
  }

  /**
   * Signed in, but this page needs access they do not have.
   */
  private function signedInButBlocked(string $denied): array {
    return [
      '#theme' => 'bos_access_denied_blocked',
      '#denied_path' => $denied,
      '#name' => $this->account->getDisplayName(),
      '#home_url' => Url::fromRoute('<front>')->toString(),
      '#logout_url' => Url::fromRoute('user.logout')->toString(),
    ];
  }

  /**
   * A visitor who found a door that isn't for them.
   *
   * Uses real service photos rather than a hand-picked file, so the page keeps
   * showing current work without anyone maintaining it.
   */
  private function publicWelcome(): array {
    return [
      '#theme' => 'bos_access_denied_public',
      '#services' => $this->featuredServices(),
      '#home_url' => Url::fromRoute('<front>')->toString(),
      '#services_url' => Url::fromUserInput('/services')->toString(),
      '#login_url' => Url::fromRoute('user.login')->toString(),
    ];
  }

  /**
   * A few published services that actually have a photo.
   *
   * @return array<int, array{title: string, url: string, image: array}>
   */
  private function featuredServices(): array {
    $storage = $this->etm->getStorage('taxonomy_term');
    try {
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('vid', 'services')
        ->condition('status', 1)
        ->exists('field_home_page_slide')
        ->range(0, 3)
        ->execute();
    }
    catch (\Throwable $e) {
      return [];
    }
    $out = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $item = $term->get('field_home_page_slide')->first();
      if (!$item || !$item->entity) {
        continue;
      }
      $out[] = [
        'title' => (string) $term->label(),
        'url' => $term->toUrl()->toString(),
        'image' => [
          '#theme' => 'image_style',
          '#style_name' => 'max_325x325',
          '#uri' => $item->entity->getFileUri(),
          // The service name is the useful description here; the photo is
          // decorative next to its own label.
          '#alt' => '',
        ],
      ];
    }
    return $out;
  }

}
