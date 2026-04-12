<?php

declare(strict_types=1);

namespace Drupal\properties\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block with dynamic links to create work orders.
 *
 * @Block(
 *   id = "property_work_order_links",
 *   admin_label = @Translation("Property Work Order Links"),
 *   category = @Translation("Custom")
 * )
 */
final class PropertyWorkOrderLinksBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected RouteMatchInterface $routeMatch;
  protected AliasManagerInterface $aliasManager;
  protected CurrentPathStack $currentPath;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
    AliasManagerInterface $alias_manager,
    CurrentPathStack $current_path
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->aliasManager = $alias_manager;
    $this->currentPath = $current_path;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('path_alias.manager'),
      $container->get('path.current')
    );
  }

  /**
   * Only allow the block on /properties/{id} and subpaths.
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    $system_path = $this->aliasManager->getPathByAlias($this->currentPath->getPath());
    $allowed = (bool) preg_match('#^/properties/\d+(?:/.*)?$#', $system_path);
    return AccessResult::allowedIf($allowed)
      ->addCacheContexts(['route', 'url.path']);
  }

  public function build(): array {
    $property_id = $this->getPropertyIdFromRoute();
    if (!$property_id) {
      return [
        '#markup' => '',
        '#cache' => [
          'contexts' => ['route', 'url.path'],
          'max-age' => 0,
        ],
      ];
    }

    // Dynamically load all work_order bundles, excluding the legacy 'estimate' bundle.
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order');
    $excluded = ['estimate'];

    $base_url = '/admin/content/work_order/add/';
    $query_param = 'edit[field_property][widget][0][target_id]=' . $property_id;

    return [
      '#type' => 'inline_template',
      '#template' => '
        <div class="property-wo-create">
          <select class="property-wo-create__select form-select" id="property-wo-type-select">
            <option value="">— Select type —</option>
            {% for key, label in options %}
              <option value="{{ key }}">{{ label }}</option>
            {% endfor %}
          </select>
          <button type="button" class="button button--primary button--small property-wo-create__go" onclick="var s=document.getElementById(\'property-wo-type-select\');if(s.value)window.open(\'{{ base_url }}\'+s.value+\'?{{ query_param }}\',\'_blank\');">Create</button>
        </div>',
      '#context' => [
        'options' => array_map(fn($info) => $info['label'], array_diff_key($bundle_info, array_flip($excluded))),
        'base_url' => $base_url,
        'query_param' => $query_param,
      ],
      '#cache' => [
        'contexts' => ['route', 'url.path'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Extract the property ID from the current route or URL.
   */
  private function getPropertyIdFromRoute(): ?string {
    $param = $this->routeMatch->getParameter('properties')
      ?? $this->routeMatch->getParameter('property')
      ?? $this->routeMatch->getParameter('property_id');

    if ($param && is_object($param) && method_exists($param, 'id')) {
      return (string) $param->id();
    }
    if (is_scalar($param) && (string) $param !== '') {
      return (string) $param;
    }

    $system_path = $this->aliasManager->getPathByAlias($this->currentPath->getPath());
    if (preg_match('#^/properties/(\d+)(?:/.*)?$#', $system_path, $m)) {
      return $m[1];
    }
    return NULL;
  }

}
