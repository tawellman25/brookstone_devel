<?php

namespace Drupal\wo_total_time\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'wo_total_time_default' formatter.
 *
 * @FieldFormatter(
 *   id = "wo_total_time_default",
 *   label = @Translation("WO Total Time Default"),
 *   field_types = {
 *     "wo_total_time"
 *   }
 * )
 */
class WoTotalTimeDefaultFormatter extends FormatterBase {

    public function viewElements(FieldItemListInterface $items, $langcode) {
      $elements = [];
  
      foreach ($items as $delta => $item) {
        $value = $item->value;
        $unit = $value < 1 ? 'hour' : 'hours';
        $elements[$delta] = [
          '#markup' => $value . ' ' . $unit
        ];
      }
  
      return $elements;
    }
  }
