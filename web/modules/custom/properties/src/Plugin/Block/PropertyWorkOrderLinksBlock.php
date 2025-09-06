<?php

namespace Drupal\properties\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\path_alias\AliasManager;

/**
 * Provides a block with dynamic links to create work orders.
 *
 * @Block(
 *   id = "property_work_order_links",
 *   admin_label = @Translation("Property Work Order Links"),
 *   category = @Translation("Custom")
 * )
 */
class PropertyWorkOrderLinksBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The alias manager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * Constructs a new PropertyWorkOrderLinksBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Path\AliasManagerInterface $alias_manager
   *   The path alias manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, AliasManager $alias_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->aliasManager = $alias_manager;
  }


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('path_alias.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $property_id = $this->getPropertyIdFromCurrentPage();

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
        // Add more work order types as needed
    ];

    $links = [];
    foreach ($work_order_types as $type_key => $type_label) {
        $url = Url::fromUri("internal:/admin/content/work_order/add/{$type_key}", [
            'query' => [
                "edit[field_property][widget][0][target_id]" => $property_id
            ]
        ]);
    
        $links[] = [
            '#type' => 'link',
            '#title' => $type_label,
            '#url' => $url,
            '#attributes' => ['target' => '_blank'] // Opens in a new tab
        ];
    }

    return [
        '#theme' => 'item_list',
        '#items' => $links,
    ];
  }

  /**
   * Extract the property ID from the current page's URL.
   */
  private function getPropertyIdFromCurrentPage() {
    $property_id = $this->routeMatch->getParameter('property_id');
    if (!$property_id) {
      // Try to resolve an alias if the property_id isn't directly available
      $current_path = \Drupal::service('path.current')->getPath();
      $path = $this->aliasManager->getPathByAlias($current_path);
      if (preg_match('/\/properties\/(\d+)/', $path, $matches)) {
        $property_id = $matches[1];
      }
    }
    return $property_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return array_merge(parent::getCacheContexts(), ['url.path']);
  }
}
