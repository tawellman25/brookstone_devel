<?php

namespace Drupal\eck_bundle_clone\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for cloning ECK bundles.
 */
class EckBundleCloneCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new EckBundleCloneCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
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
   * Clone an ECK bundle including fields, displays, field groups and labels.
   *
   * @command eck:clone-bundle
   * @aliases eck-bundle-clone,eck:clone-bundle
   *
   * @param string $entity_type_id
   *   The ECK content entity type ID (e.g. "estimate").
   * @param string $source_bundle_id
   *   The source bundle machine name.
   * @param string $new_bundle_id
   *   The new bundle machine name.
   * @option label
   *   (optional) The label for the new bundle. If omitted, a prettified
   *   version of the new bundle machine name will be used.
   *
   * @usage eck:clone-bundle estimate landscaping ssrepair
   *   Clone the "landscaping" bundle of the "estimate" entity type to
   *   "ssrepair".
   */
  public function cloneBundle(
    string $entity_type_id,
    string $source_bundle_id,
    string $new_bundle_id,
    array $options = ['label' => '']
  ): void {
    $bundle_entity_type_id = $entity_type_id . '_type';

    $bundle_storage = $this->entityTypeManager->getStorage($bundle_entity_type_id);

    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $source */
    $source = $bundle_storage->load($source_bundle_id);
    if (!$source) {
      $this->logger()->error(dt('Source bundle "@bundle" not found on entity type "@type".', [
        '@bundle' => $source_bundle_id,
        '@type' => $entity_type_id,
      ]));
      return;
    }

    if ($bundle_storage->load($new_bundle_id)) {
      $this->logger()->error(dt('Bundle with ID "@id" already exists on entity type "@type".', [
        '@id' => $new_bundle_id,
        '@type' => $entity_type_id,
      ]));
      return;
    }

    $values = $source->toArray();

    // ECK bundle ID is stored in "type".
    $values['type'] = $new_bundle_id;

    if (!empty($options['label'])) {
      $values['name'] = $options['label'];
    }
    else {
      $values['name'] = ucfirst(str_replace('_', ' ', $new_bundle_id));
    }

    unset($values['uuid']);

    $new_bundle = $bundle_storage->create($values);
    $new_bundle->save();

    $this->cloneFields($entity_type_id, $source_bundle_id, $new_bundle_id);
    $this->cloneFormDisplays($entity_type_id, $source_bundle_id, $new_bundle_id);
    $this->cloneViewDisplays($entity_type_id, $source_bundle_id, $new_bundle_id);
    $this->cloneFieldGroups($entity_type_id, $source_bundle_id, $new_bundle_id);

    $this->logger()->success(dt(
      'Cloned bundle "@source" to "@new" for entity type "@type".',
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
   * @param string $entity_type_id
   *   The ECK entity type ID.
   * @param string $source_bundle_id
   *   The source bundle machine name.
   * @param string $new_bundle_id
   *   The new bundle machine name.
   */
  protected function cloneFields(
    string $entity_type_id,
    string $source_bundle_id,
    string $new_bundle_id
  ): void {
    $field_config_storage = $this->entityTypeManager->getStorage('field_config');

    $ids = $field_config_storage->getQuery()
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

      $existing_id = $entity_type_id . '.' . $new_bundle_id . '.' . $field_name;
      if ($field_config_storage->load($existing_id)) {
        continue;
      }

      $duplicate = $field_config->createDuplicate();
      $duplicate->set('bundle', $new_bundle_id);
      $duplicate->set('id', $existing_id);
      $duplicate->set('uuid', NULL);
      $duplicate->save();
    }
  }

  /**
   * Clone form displays (entity_form_display) from one bundle to another.
   *
   * @param string $entity_type_id
   *   The ECK entity type ID.
   * @param string $source_bundle_id
   *   The source bundle machine name.
   * @param string $new_bundle_id
   *   The new bundle machine name.
   */
  protected function cloneFormDisplays(
    string $entity_type_id,
    string $source_bundle_id,
    string $new_bundle_id
  ): void {
    $storage = $this->entityTypeManager->getStorage('entity_form_display');

    $ids = $storage->getQuery()
      ->condition('targetEntityType', $entity_type_id)
      ->condition('bundle', $source_bundle_id)
      ->execute();

    if (!$ids) {
      return;
    }

    $displays = $storage->loadMultiple($ids);

    foreach ($displays as $display) {
      /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $display */
      $old_id = $display->id();
      $new_id = str_replace(
        $entity_type_id . '.' . $source_bundle_id . '.',
        $entity_type_id . '.' . $new_bundle_id . '.',
        $old_id
      );

      if ($storage->load($new_id)) {
        continue;
      }

      $duplicate = $display->createDuplicate();
      $duplicate->set('bundle', $new_bundle_id);
      $duplicate->set('id', $new_id);
      $duplicate->set('uuid', NULL);
      $duplicate->save();
    }
  }

  /**
   * Clone view displays (entity_view_display) from one bundle to another.
   *
   * @param string $entity_type_id
   *   The ECK entity type ID.
   * @param string $source_bundle_id
   *   The source bundle machine name.
   * @param string $new_bundle_id
   *   The new bundle machine name.
   */
  protected function cloneViewDisplays(
    string $entity_type_id,
    string $source_bundle_id,
    string $new_bundle_id
  ): void {
    $storage = $this->entityTypeManager->getStorage('entity_view_display');

    $ids = $storage->getQuery()
      ->condition('targetEntityType', $entity_type_id)
      ->condition('bundle', $source_bundle_id)
      ->execute();

    if (!$ids) {
      return;
    }

    $displays = $storage->loadMultiple($ids);

    foreach ($displays as $display) {
      /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display */
      $old_id = $display->id();
      $new_id = str_replace(
        $entity_type_id . '.' . $source_bundle_id . '.',
        $entity_type_id . '.' . $new_bundle_id . '.',
        $old_id
      );

      if ($storage->load($new_id)) {
        continue;
      }

      $duplicate = $display->createDuplicate();
      $duplicate->set('bundle', $new_bundle_id);
      $duplicate->set('id', $new_id);
      $duplicate->set('uuid', NULL);
      $duplicate->save();
    }
  }

  /**
   * Clone Field Group definitions from one bundle to another.
   *
   * Only runs if the field_group entity type exists.
   *
   * @param string $entity_type_id
   *   The ECK entity type ID.
   * @param string $source_bundle_id
   *   The source bundle machine name.
   * @param string $new_bundle_id
   *   The new bundle machine name.
   */
  protected function cloneFieldGroups(
    string $entity_type_id,
    string $source_bundle_id,
    string $new_bundle_id
  ): void {
    // Be defensive: only proceed if the entity type actually exists.
    if (!$this->entityTypeManager->hasDefinition('field_group')) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('field_group');

    $ids = $storage->getQuery()
      ->condition('entity_type', $entity_type_id)
      ->condition('bundle', $source_bundle_id)
      ->execute();

    if (!$ids) {
      return;
    }

    $groups = $storage->loadMultiple($ids);

    foreach ($groups as $group) {
      /** @var \Drupal\field_group\Entity\FieldGroup $group */
      $old_id = $group->id();
      $new_id = str_replace(
        $entity_type_id . '.' . $source_bundle_id . '.',
        $entity_type_id . '.' . $new_bundle_id . '.',
        $old_id
      );

      if ($storage->load($new_id)) {
        continue;
      }

      $duplicate = $group->createDuplicate();
      $duplicate->set('bundle', $new_bundle_id);
      $duplicate->set('id', $new_id);
      $duplicate->set('uuid', NULL);
      $duplicate->save();
    }
  }

}
