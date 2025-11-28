<?php

namespace Drupal\wo_total_time\Plugin\Field\FieldWidget;

use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'wo_total_time_default' widget.
 *
 * @FieldWidget(
 *   id = "wo_total_time_default",
 *   label = @Translation("WO Total Time Default"),
 *   field_types = {
 *     "wo_total_time"
 *   }
 * )
 */
class WoTotalTimeDefaultWidget extends WidgetBase {

  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['value'] = [
      '#type' => 'number',
      '#title' => $this->t('Total Time (hours)'),
      '#default_value' => isset($items[$delta]->value) ? $items[$delta]->value : '',
      '#step' => 0.01,  // Adjust step for decimal precision
    ];
    return $element;
  }
}
