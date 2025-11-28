<?php

declare(strict_types=1);

namespace Drupal\properties\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
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

    $work_order_types = [
      'aerating' => 'Aerating',
      'aspen_twig_gall' => 'Aspen Twig Gall',
      'christmas_decorations' => 'Christmas Decorations',
      'cooley_spruce_gall' => 'Cooley Spruce Gall',
      'deciduous_bore' => 'Deciduous Bore',
      'deer_prevention' => 'Deer Prevention',
      'dethatching' => 'Dethatching',
      'dormant_oil' => 'Dormant Oil',
      'estimate' => 'Estimate',
      'fall_cleanup' => 'Fall Cleanup',
      'fertilizing' => 'Fertilizing',
      'fertilizing_trees_and_shrubs' => 'Fertilizing Trees and Shrubs',
      'grub_prevention' => 'Grub Prevention',
      'in_house_tasks' => 'In House Tasks',
      'landscaping' => 'Landscaping',
      'lawn_mowing' => 'Lawn Mowing',
      'misc_services' => 'Misc Services',
      'pinion_pine_ips_beetle' => 'Pinion Pine Ips Beetle',
      'pre_emergent' => 'Pre-emergent',
      'snow_removal' => 'Snow Removal',
      'special_mowing' => 'Special Mowing',
      'spring_cleanup' => 'Spring Cleanup',
      'sprinkler_check_up' => 'Sprinkler Check-Up',
      'sprinkler_design' => 'Sprinkler Design',
      'sprinkler_installation' => 'Sprinkler Installation',
      'sprinkler_repair' => 'Sprinkler Repair',
      'sprinkler_start_up' => 'Sprinkler Start-Up',
      'sprinkler_winterizing' => 'Sprinkler Winterizing',
      'summer_pruning' => 'Summer Pruning',
      'trunk_bore' => 'Trunk Bore',
      'weed_pulling' => 'Weed Pulling',
    ];

    $links = [];
    foreach ($work_order_types as $type_key => $type_label) {
      $url = Url::fromUri("internal:/admin/content/work_order/add/{$type_key}", [
        'query' => [
          'edit[field_property][widget][0][target_id]' => $property_id,
        ],
      ]);
      $links[] = Link::fromTextAndUrl($type_label, $url)->toRenderable() + [
        '#attributes' => ['target' => '_blank'],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $links,
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
