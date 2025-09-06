<?php

namespace Drupal\wo_total_time\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'wo_total_time' field type.
 *
 * @FieldType(
 *   id = "wo_total_time",
 *   label = @Translation("WO Total Time"),
 *   description = @Translation("Stores total time for a work order."),
 *   default_widget = "wo_total_time_default",
 *   default_formatter = "wo_total_time_default"
 * )
 */
class WoTotalTime extends FieldItemBase {

  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = [];
    $properties['value'] = DataDefinition::create('float')
      ->setLabel(t('Total time in hours'));
    return $properties;
  }

  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [];
    $schema['columns']['value'] = [
      'type' => 'numeric',
      'precision' => 10,  // Total number of digits including decimal places
      'scale' => 2,       // Number of digits after the decimal point
      'not null' => FALSE,
    ];
    return $schema;
  }
}


