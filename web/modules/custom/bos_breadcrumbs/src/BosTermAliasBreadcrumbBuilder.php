<?php

declare(strict_types=1);

namespace Drupal\bos_breadcrumbs;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Builds breadcrumbs for public taxonomy term pages from their URL alias.
 *
 * WHY THIS EXISTS
 * Core's taxonomy breadcrumb builder (priority 1002) claims every
 * entity.taxonomy_term.canonical route and builds the trail from the
 * VOCABULARY HIERARCHY — which is flat for our public vocabularies, so the
 * trail is just "Home". This builder (priority 1010, above core) rebuilds the
 * trail from the page's URL ALIAS instead, using each parent route's real page
 * title (view title / term name), matching how the view pages already behave.
 *
 * WHY THE ALLOWLIST IS EXPLICIT — DO NOT BROADEN IT.
 * Only the four PUBLIC, marketing-facing vocabularies below get this treatment.
 * BOS has ~1,300 OPERATIONAL taxonomy pages that are publicly reachable and
 * indexed (a known, separate problem); giving them a breadcrumb trail would
 * improve their search presence — the exact opposite of what we want. This is
 * an allowlist, never an "all-except" list. Everything not listed returns FALSE
 * from applies() and falls through to core's builder unchanged.
 *
 * This mirrors core's PathBasedBreadcrumbBuilder, with three deliberate
 * differences: (1) it only applies to the allowlisted vocabularies; (2) an
 * intermediate segment that does not resolve to a route (or whose route has no
 * title) is SKIPPED silently — never emitted as a dead link or humanised into a
 * fake crumb; (3) each resolved term's cache tags are added so renaming a parent
 * invalidates its children's breadcrumbs.
 */
class BosTermAliasBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * Public vocabularies this builder governs. EXPLICIT allowlist — see the class
   * docblock before changing. Adding an operational vocabulary here is a bug.
   */
  public const ALLOWED_VIDS = [
    'services',
    'material_types',
    'backflow_device_types',
    'backflow_uses',
  ];

  public function __construct(
    protected RequestContext $context,
    protected AccessManagerInterface $accessManager,
    protected RequestMatcherInterface $router,
    protected InboundPathProcessorInterface $pathProcessor,
    ConfigFactoryInterface $configFactory,
    protected TitleResolverInterface $titleResolver,
    protected AccountInterface $currentUser,
    protected CurrentPathStack $currentPath,
    protected PathMatcherInterface $pathMatcher,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->config = $configFactory->get('system.site');
  }

  /**
   * The system.site config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    if ($route_match->getRouteName() !== 'entity.taxonomy_term.canonical') {
      return FALSE;
    }
    $vid = $this->termVid($route_match->getParameter('taxonomy_term'));
    return $vid !== NULL && in_array($vid, self::ALLOWED_VIDS, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    // The trail depends on the parent path and on the viewer (access-gated
    // crumbs). Access results merged below also carry their own contexts/tags.
    $breadcrumb->addCacheContexts(['url.path.parent', 'url.path.is_front', 'user.permissions']);

    if ($this->pathMatcher->isFrontPage()) {
      return $breadcrumb;
    }

    $links = [];
    // Use the actual (alias) request path so the trail follows the URL, and
    // walk it left-to-right by popping the last (current) segment first — that
    // also omits the current page from the trail.
    $path = trim($this->context->getPathInfo(), '/');
    $path_elements = explode('/', $path);
    $exclude = [];
    $exclude[$this->config->get('page.front')] = TRUE;
    $exclude['/user'] = TRUE;

    while (count($path_elements) > 1) {
      array_pop($path_elements);
      $route_request = $this->getRequestForPath('/' . implode('/', $path_elements), $exclude);
      if (!$route_request) {
        // Segment does not resolve to a route — skip it silently.
        continue;
      }
      $crumb_match = RouteMatch::createFromRequest($route_request);
      $access = $this->accessManager->check($crumb_match, $this->currentUser, NULL, TRUE);
      $breadcrumb = $breadcrumb->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }

      // Resolve the crumb title. For a taxonomy term route use the term's own
      // label (a clean string, and it lets us tag the term so renaming a parent
      // invalidates its children's breadcrumbs). For anything else use the
      // route's real title, accepting only a string/markup value — a render
      // array or missing title is skipped, never humanised into a fake crumb.
      $title = NULL;
      if ($crumb_match->getRouteName() === 'entity.taxonomy_term.canonical') {
        $param = $crumb_match->getParameter('taxonomy_term');
        $term = $param instanceof EntityInterface
          ? $param
          : (is_scalar($param) ? $this->entityTypeManager->getStorage('taxonomy_term')->load($param) : NULL);
        if (!$term) {
          continue;
        }
        $breadcrumb = $breadcrumb->addCacheableDependency($term);
        $title = trim((string) $term->label());
      }
      else {
        $resolved = $this->titleResolver->getTitle($route_request, $crumb_match->getRouteObject());
        if (is_string($resolved) || $resolved instanceof MarkupInterface) {
          $title = trim((string) $resolved);
        }
      }
      if ($title === NULL || $title === '') {
        continue;
      }
      $links[] = new Link($title, Url::fromRouteMatch($crumb_match));
    }

    // Home is always first.
    $links[] = Link::createFromRoute($this->t('Home'), '<front>');

    return $breadcrumb->setLinks(array_reverse($links));
  }

  /**
   * Resolve a taxonomy_term route parameter (entity or id) to its vocabulary id.
   */
  protected function termVid(mixed $term): ?string {
    if ($term instanceof EntityInterface) {
      return $term->bundle();
    }
    if (is_scalar($term)) {
      $loaded = $this->entityTypeManager->getStorage('taxonomy_term')->load($term);
      return $loaded ? $loaded->bundle() : NULL;
    }
    return NULL;
  }

  /**
   * Build a matched Request for a path, resolving aliases. NULL if unresolvable.
   *
   * Mirrors core's PathBasedBreadcrumbBuilder::getRequestForPath().
   */
  protected function getRequestForPath(string $path, array $exclude): ?Request {
    if (!empty($exclude[$path])) {
      return NULL;
    }
    try {
      $request = Request::create($path);
    }
    catch (BadRequestException) {
      return NULL;
    }
    $request->headers->set('Accept', 'text/html');
    $processed = $this->pathProcessor->processInbound($path, $request);
    if (empty($processed) || !empty($exclude[$processed])) {
      return NULL;
    }
    $this->currentPath->setPath($processed, $request);
    try {
      $request->attributes->add($this->router->matchRequest($request));
      return $request;
    }
    catch (ParamNotConvertedException | ResourceNotFoundException | MethodNotAllowedException | AccessDeniedHttpException | NotFoundHttpException) {
      return NULL;
    }
  }

}
