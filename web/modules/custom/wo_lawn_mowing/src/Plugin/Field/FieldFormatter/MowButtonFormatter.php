<?php

namespace Drupal\wo_lawn_mowing\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Url;

/**
 * Plugin implementation of the 'mow_button_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "mow_button_formatter",
 *   label = @Translation("Mow Button"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class MowButtonFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      // Generate the URL for your custom route.
      $url = Url::fromRoute('wo_lawn_mowing.create_work_order');

      // Set the 'target' attribute to '_blank' to open in a new tab.
      $options = ['attributes' => ['target' => '_blank']];

      // Generate the button markup.
      $link = \Drupal\Core\Link::fromTextAndUrl($this->t('Mow'), $url)->toRenderable();
      $link['#options'] = $options;

      // Add the button to the renderable elements array.
      $elements[$delta] = $link;
    }

    return $elements;
  }

}
