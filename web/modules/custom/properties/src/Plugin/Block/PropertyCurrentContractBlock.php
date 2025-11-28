<?php

declare(strict_types=1);

namespace Drupal\properties\Plugin\Block;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
final class PropertyCurrentContractBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Current route match.
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * Bundle info service.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    EntityTypeBundleInfoInterface $bundle_info,
    TimeInterface $time
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->bundleInfo = $bundle_info;
    $this->time = $time;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $items = [];
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $current_year = $now->format('Y');

    $property = $this->getCurrentPropertyEntity();
    if (!$property) {
      return [
        '#markup' => '',
        '#cache' => [
          'contexts' => ['route'],
          'max-age' => 0,
        ],
      ];
    }

    // Query contracts linked to this property for the current year.
    $storage_contracts = $this->entityTypeManager->getStorage('contracts');
    $contract_ids = $storage_contracts->getQuery()
      ->condition('field_property', $property->id())
      ->condition('field_contract_year', $current_year)
      ->accessCheck(TRUE)
      ->execute();

    if ($contract_ids) {
      $contracts = $storage_contracts->loadMultiple($contract_ids);

      foreach ($contracts as $contract) {
        // Iterate referenced sections.
        if ($contract->hasField('field_contract_sections') && !$contract->get('field_contract_sections')->isEmpty()) {
          /** @var \Drupal\Core\Entity\EntityInterface[] $sections */
          $sections = $contract->get('field_contract_sections')->referencedEntities();
          foreach ($sections as $section) {
            $items[] = $this->buildSectionLine($section);
          }
        }
      }
    }

    $build = [
      '#theme' => 'item_list',
      '#title' => $this->t('Current Contract'),
      '#items' => array_filter($items),
      '#empty' => $this->t('No current contract items.'),
      '#cache' => [
        'contexts' => ['route'],
        'tags' => $this->collectCacheTags($property, $contract_ids ?? []),
        'max-age' => 0,
      ],
    ];

    return $build;
  }

  /**
   * Build a single item line for a contract section.
   */
  protected function buildSectionLine(EntityInterface $section): array|string|null {
    $entity_type_id = $section->getEntityTypeId(); // Expected: 'contract_sections'.
    $bundle = $section->bundle();
    $bundle_label = $this->bundleInfo->getBundleInfo($entity_type_id)[$bundle]['label'] ?? $bundle;

    // Text value to display.
    $value = '';
    if ($section->hasField('field_do_you_want') && !$section->get('field_do_you_want')->isEmpty()) {
      $value = (string) $section->get('field_do_you_want')->value;
    }

    // Optional link to referenced Work Order.
    $link_render = NULL;
    if ($section->hasField('field_work_order') && !$section->get('field_work_order')->isEmpty()) {
      $wo = $section->get('field_work_order')->entity;
      if ($wo) {
        $url = Url::fromRoute('entity.work_order.canonical', ['work_order' => $wo->id()]);
        $text = $value !== '' ? $value : $this->t('View work order');
        $link_render = Link::fromTextAndUrl($text, $url)->toRenderable();
      }
    }

    // If no value and no link, skip.
    if ($value === '' && !$link_render) {
      return NULL;
    }

    // Return an inline render array: "<Bundle Label>: <link or text>".
    return [
      '#type' => 'inline_template',
      '#template' => '{{ label }}: {% if link %}{{ link }}{% else %}{{ text }}{% endif %}',
      '#context' => [
        'label' => $bundle_label,
        'text' => $value,
        'link' => $link_render,
      ],
    ];
  }

  /**
   * Get the current Property entity from the route.
   */
  protected function getCurrentPropertyEntity(): ?EntityInterface {
    // ECK route param usually matches entity type id; try both.
    $prop = $this->routeMatch->getParameter('properties') ?? $this->routeMatch->getParameter('property');
    if ($prop instanceof EntityInterface) {
      return $prop;
    }
    if (is_scalar($prop)) {
      return $this->entityTypeManager->getStorage('properties')->load($prop) ?: NULL;
    }
    return NULL;
  }

  /**
   * Collect cache tags.
   *
   * @param \Drupal\Core\Entity\EntityInterface $property
   * @param array<int> $contract_ids
   *
   * @return string[]
   */
  protected function collectCacheTags(EntityInterface $property, array $contract_ids): array {
    $tags = $property->getCacheTags();

    if ($contract_ids) {
      $tags = array_merge($tags, $this->entityTypeManager->getStorage('contracts')->loadMultiple($contract_ids)[array_key_first($contract_ids)]->getEntityType()->getListCacheTags());
      foreach ($contract_ids as $cid) {
        $tags[] = "contracts:$cid";
      }
    }

    return array_values(array_unique($tags));
  }

}
