<?php

namespace Drupal\eck_bundle_clone\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for cloning ECK bundles.
 *
 * IMPORTANT DESIGN DECISION:
 * - This module clones ONLY the bundle definition + fields (+ base field overrides).
 * - It DOES NOT clone entity_form_display / entity_view_display.
 *
 * Reason:
 * Cloning display config often copies invalid/inconsistent third_party_settings
 * (field_group / field_layout / layout builder metadata), which can corrupt the
 * cloned bundle’s display config and cause errors when admins manage groups.
 *
 * After cloning, admins must configure displays/groups manually in the UI.
 */
final class EckBundleCloneCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new EckBundleCloneCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Clone an ECK bundle (bundle config + fields + base field overrides).
   *
   * Displays are NOT cloned by design:
   * - No core.entity_form_display.*
   * - No core.entity_view_display.*
   * - No third_party_settings copied from displays (field_group/field_layout/etc.)
   *
   * @command eck:clone-bundle
   * @aliases eck-bundle-clone
   *
   * @param string $entity_type_id
   *   The ECK content entity type ID (e.g. "estimate", "sop").
   * @param string $source_bundle_id
   *   The source bundle machine name.
   * @param string $new_bundle_id
   *   The new bundle machine name.
   *
   * @option label
   *   (optional) The label for the new bundle.
   *
   * @usage eck:clone-bundle sop system_procedures training --label="Training"
   */
  public function cloneBundle(
    string $entity_type_id,
    string $source_bundle_id,
    string $new_bundle_id,
    array $options = ['label' => '']
  ): void {
    $bundle_entity_type_id = $entity_type_id . '_type';

    if (!$this->entityTypeManager->hasDefinition($bundle_entity_type_id)) {
      throw new \RuntimeException(sprintf(
        'Bundle entity type "%s" does not exist. Is "%s" a valid ECK entity type?',
        $bundle_entity_type_id,
        $entity_type_id
      ));
    }

    $bundle_storage = $this->entityTypeManager->getStorage($bundle_entity_type_id);

    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $source */
    $source = $bundle_storage->load($source_bundle_id);
    if (!$source) {
      throw new \RuntimeException(sprintf(
        'Source bundle "%s" not found for entity type "%s".',
        $source_bundle_id,
        $entity_type_id
      ));
    }

    if ($bundle_storage->load($new_bundle_id)) {
      throw new \RuntimeException(sprintf(
        'Target bundle "%s" already exists for entity type "%s".',
        $new_bundle_id,
        $entity_type_id
      ));
    }

    // Clone bundle config entity (includes 3rd-party settings on the bundle
    // itself like Automatic Entity Label). This is safe and desired.
    $values = $source->toArray();

    // For ECK bundle config entities, the ID is in "type" and label in "name".
    $values['type'] = $new_bundle_id;

    $label = (string) ($options['label'] ?? '');
    if ($label !== '') {
      $values['name'] = $label;
    }
    else {
      $values['name'] = ucfirst(str_replace('_', ' ', $new_bundle_id));
    }

    unset($values['uuid']);

    $new_bundle = $bundle_storage->create($values);
    $new_bundle->save();

    // Clone structural config tied to the bundle.
    $this->cloneFields($entity_type_id, $source_bundle_id, $new_bundle_id);
    $this->cloneBaseFieldOverrides($entity_type_id, $source_bundle_id, $new_bundle_id);

    $this->logger()->success(dt(
      'Cloned bundle "@source" -> "@new" for entity type "@type". Displays were NOT cloned by design; configure form/view displays and field groups manually.',
      [
        '@source' => $source_bundle_id,
        '@new' => $new_bundle_id,
        '@type' => $entity_type_id,
      ]
    ));
  }

  /**
   * Clone field instances (field_config) from one bundle to another.
   *
   * Field storage is shared between bundles, so we only create field_config
   * entities for the new bundle.
   */
  protected function cloneFields(string $entity_type_id, string $source_bundle_id, string $new_bundle_id): void {
    $field_config_storage = $this->entityTypeManager->getStorage('field_config');

    $ids = $field_config_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type', $entity_type_id)
      ->condition('bundle', $source_bundle_id)
      ->execute();

    if (!$ids) {
      return;
    }

    $field_configs = $field_config_storage->loadMultiple($ids);

    foreach ($field_configs as $field_config) {
      /** @var \Drupal\field\Entity\FieldConfig $field_config */
      $field_name = $field_config->getName();
      $new_id = $entity_type_id . '.' . $new_bundle_id . '.' . $field_name;

      if ($field_config_storage->load($new_id)) {
        continue;
      }

      $duplicate = $field_config->createDuplicate();
      $duplicate->set('bundle', $new_bundle_id);
      $duplicate->set('id', $new_id);
      $duplicate->set('uuid', NULL);
      $duplicate->save();
    }
  }

  /**
   * Clone base field overrides from one bundle to another.
   *
   * IDs are: {entity_type}.{bundle}.{field_name}
   */
  protected function cloneBaseFieldOverrides(string $entity_type_id, string $source_bundle_id, string $new_bundle_id): void {
    if (!$this->entityTypeManager->hasDefinition('base_field_override')) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('base_field_override');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type', $entity_type_id)
      ->condition('bundle', $source_bundle_id)
      ->execute();

    if (!$ids) {
      return;
    }

    $overrides = $storage->loadMultiple($ids);

    foreach ($overrides as $override) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $override */
      $field_name = $override->get('field_name');
      $new_id = $entity_type_id . '.' . $new_bundle_id . '.' . $field_name;

      if ($storage->load($new_id)) {
        continue;
      }

      $duplicate = $override->createDuplicate();
      $duplicate->set('bundle', $new_bundle_id);
      $duplicate->set('id', $new_id);
      $duplicate->set('uuid', NULL);
      $duplicate->save();
    }
  }

}
