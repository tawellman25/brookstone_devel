<?php

namespace Drupal\system_readiness\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the System Readiness entity.
 *
 * @ContentEntityType(
 *   id = "system_readiness",
 *   label = @Translation("System Readiness"),
 *   label_collection = @Translation("System Readiness"),
 *   label_singular = @Translation("System Readiness item"),
 *   label_plural = @Translation("System Readiness items"),
 *   label_count = @PluralTranslation(
 *     singular = "@count System Readiness item",
 *     plural = "@count System Readiness items"
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\system_readiness\SystemReadinessListBuilder",
 *     "form" = {
 *       "default" = "Drupal\system_readiness\Form\SystemReadinessForm",
 *       "add" = "Drupal\system_readiness\Form\SystemReadinessForm",
 *       "edit" = "Drupal\system_readiness\Form\SystemReadinessForm",
 *       "delete" = "Drupal\system_readiness\Form\SystemReadinessDeleteForm"
 *     },
 *     "access" = "Drupal\system_readiness\SystemReadinessAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "system_readiness",
 *   admin_permission = "administer system readiness",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "uid" = "user_id"
 *   },
 *   links = {
 *     "canonical" = "/admin/operations/system_content/system-readiness/{system_readiness}",
 *     "add-form" = "/admin/operations/system_content/system-readiness/add",
 *     "edit-form" = "/admin/operations/system_content/system-readiness/{system_readiness}/edit",
 *     "delete-form" = "/admin/operations/system_content/system-readiness/{system_readiness}/delete",
 *     "collection" = "/admin/operations/system_content/system-readiness"
 *   }
 * )
 */
final class SystemReadiness extends ContentEntityBase implements SystemReadinessInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->getOwnerId() === NULL) {
      $this->setOwnerId($this->currentUser()->id());
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Title.
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Entity Name'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    
    $fields['field_entity_type'] = BaseFieldDefinition::create('list_string')
        ->setLabel(t('Entity Type'))
        ->setRequired(TRUE)
        ->setSettings([
            'allowed_values' => [
            'node' => 'Node',
            'eck' => 'ECK',
            'taxonomy' => 'Taxonomy',
            'menu' => 'Menu',
            'config' => 'Config',
            'user' => 'User',
            'media' => 'Media',
            'other' => 'Other',
            ],
        ])
        ->setDefaultValue('eck')
        ->setDescription(t('High-level entity family for the machine name.'))
        ->setDisplayOptions('form', [
            'type' => 'options_select',
            'weight' => 1,
        ])
        ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'list_default',
            'weight' => 1,
        ])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    
    // Entity Type (machine name).
    $fields['field_machine_name'] = BaseFieldDefinition::create('string')
        ->setLabel(t('Machine Name'))
        ->setRequired(TRUE)
        ->setSettings([
            'max_length' => 128,
            'text_processing' => 0,
        ])
        ->setDescription(t('Machine name of the entity type/bundle target (e.g., node, taxonomy_term, work_order, sop).'))
        ->setDisplayOptions('form', [
            'type' => 'string_textfield',
            'weight' => 2,
        ])
        ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'string',
            'weight' => 2,
        ])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);

    // Bundle Title.
    $fields['field_bundle_title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Bundle Name'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 3,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Bundle (machine name) - optional.
    $fields['field_bundle'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Bundle'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setDescription(t('Optional bundle machine name (leave blank for unbundled entity types).'))
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 4,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Environment.
    $fields['field_environment'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Environment'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'dev' => 'DEV',
          'stage' => 'STAGE',
          'live' => 'LIVE',
        ],
      ])
      ->setDefaultValue('dev')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 5,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Checklist booleans.
    $fields['field_fields_complete'] = self::boolField('Fields Complete', 10);
    $fields['field_form_display_complete'] = self::boolField('Form Display Complete', 11);
    $fields['field_view_display_complete'] = self::boolField('View Display Complete', 12);
    $fields['field_permissions_complete'] = self::boolField('Permissions Complete', 13);
    $fields['field_pathauto_complete'] = self::boolField('Pathauto Complete', 14);
    $fields['field_views_complete'] = self::boolField('Views Complete', 15);
    $fields['field_actions_logic_complete'] = self::boolField('Actions / Logic Complete', 16);
    $fields['field_tested_complete'] = self::boolField('Tested', 17);
    $fields['field_live_ready'] = self::boolField('Production Ready', 18);

    // Priority.
    $fields['field_priority'] = BaseFieldDefinition::create('list_integer')
      ->setLabel(t('Priority'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          1 => '1 - Critical',
          2 => '2 - High',
          3 => '3 - Normal',
          4 => '4 - Low',
        ],
      ])
      ->setDefaultValue(3)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Status.
    $fields['field_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'not_started' => 'Not Started',
          'in_progress' => 'In Progress',
          'blocked' => 'Blocked',
          'done' => 'Done',
        ],
      ])
      ->setDefaultValue('in_progress')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 21,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 21,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Blocking Issue.
    $fields['field_blocking_issue'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Blocking Issue'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 22,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 22,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Notes.
    $fields['field_notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Notes'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 23,
        'settings' => ['rows' => 6],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 23,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Last Reviewed.
    $fields['field_last_reviewed'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Last Reviewed'))
      ->setRequired(FALSE)
      ->setSettings([
        'datetime_type' => 'datetime',
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 24,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => 24,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Owner.
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Teammate'))
      ->setDescription(t('The user responsible for this readiness item.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getCurrentUserId')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 30,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'autocomplete_type' => 'tags',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created / Changed.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  private static function boolField(string $label, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('boolean')
      ->setLabel(t($label))
      ->setRequired(FALSE)
      ->setDefaultValue(FALSE)
      ->setSettings(['on_label' => 'Yes', 'off_label' => 'No'])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => $weight,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * Default value callback for user_id base field.
   */
  public static function getCurrentUserId(): array {
    return [\Drupal::currentUser()->id()];
  }

}
