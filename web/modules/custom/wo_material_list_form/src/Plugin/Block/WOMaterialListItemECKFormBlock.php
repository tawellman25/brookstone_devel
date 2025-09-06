<?php

namespace Drupal\wo_material_list_form\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block with the WO Material List Item ECK form.
 *
 * @Block(
 *   id = "wo_material_list_item_eck_form_block",
 *   admin_label = @Translation("WO Material List Item ECK Form"),
 *   provider = "wo_material_list_form"
 * )
 */
class WOMaterialListItemECKFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new WOMaterialListItemECKFormBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Specify the bundle for the WO Material List Item entity.
    $entity = $this->entityTypeManager
      ->getStorage('wo_material_list_item')
      ->create([
        'type' => 'items', // Replace 'items' with your actual bundle name.
      ]);

    // Get the parent WO Material List ID from the route parameters
    $parent_list = \Drupal::routeMatch()->getParameter('wo_material_list');
    if ($parent_list) {
      $entity->set('field_list_id', ['target_id' => $parent_list->id()]);
      \Drupal::logger('wo_material_list_form')->notice('Prefilled field_list_id with @id', ['@id' => $parent_list->id()]);
    } else {
      \Drupal::logger('wo_material_list_form')->notice('No parent WO Material List ID found to prefill field_list_id.');
    }

    // Build the entity form.
    return \Drupal::service('entity.form_builder')->getForm($entity, 'default');
  }

}