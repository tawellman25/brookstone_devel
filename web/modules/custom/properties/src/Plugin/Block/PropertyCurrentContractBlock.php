<?php

namespace Drupal\properties\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides a 'PropertyCurrentContractBlock' block for ECK entities.
 *
 * @Block(
 *   id = "properties_current_contract_block",
 *   admin_label = @Translation("Properties Current Contracts Block"),
 *   category = @Translation("Custom"),
 *   provider = "properties"
 * )
 */
class PropertyCurrentContractBlock extends BlockBase implements ContainerFactoryPluginInterface {

    protected $entityTypeManager;

    public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager) {
      parent::__construct($configuration, $plugin_id, $plugin_definition);
      $this->entityTypeManager = $entityTypeManager;
    }
  
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
      $content = [];
      $current_year = date('Y');
  
      // Assume we are on a page of an ECK entity type 'properties'.
      $property = \Drupal::routeMatch()->getParameter('properties');
      if ($property) {
        $query = $this->entityTypeManager->getStorage('contracts')->getQuery()
        ->condition('field_property', $property->id())
        ->condition('field_contract_year', $current_year)
        ->accessCheck(FALSE); // or TRUE, depending on your requirements
        $contract_ids = $query->execute();
        $contracts = $this->entityTypeManager->getStorage('contracts')->loadMultiple($contract_ids);
  
        foreach ($contracts as $contract) {
          $section_ids = $contract->get('field_contract_sections')->getValue();
          foreach ($section_ids as $section_id) {
            $section = $this->entityTypeManager->getStorage('contract_sections')->load($section_id['target_id']);
            if ($section) {
              // Retrieve the bundle name to use as a label
              $bundle_type = $section->bundle();
              $bundle_info = \Drupal::entityTypeManager()->getStorage('entity_type')->load($bundle_type);
              $bundle_label = $bundle_info ? $bundle_info->label() : $bundle_type;
  
              // Get the value of 'field_do_you_want'
              $value = $section->get('field_do_you_want')->value;
  
              // Get the Work Order link if available
              $work_order_id = $section->get('field_work_order')->target_id;
              if ($work_order_id) {
                $url = Url::fromRoute('entity.work_order.canonical', ['work_order' => $work_order_id]);
                $link = Link::fromTextAndUrl($value, $url)->toString();
              } else {
                $link = $value; // No link if no work order is referenced
              }
  
              if (!empty($value)) {
                $content[] = "{$bundle_label}: {$link}";
              }
            }
          }
        }
      }
  
      return [
        '#theme' => 'item_list',
        '#items' => $content,
        '#title' => 'Current Contract',
      ];
    }
  }