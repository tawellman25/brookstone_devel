<?php

declare(strict_types=1);

namespace Drupal\bos_hoa\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Google Maps polygon-drawing widget for the HOA boundary geofield.
 *
 * Reuses BOS's existing Google Maps key (geofield_map.settings). Draws/edits a
 * single polygon and stores it as WKT in the geofield 'value' — exactly what
 * bos_hoa's point-in-polygon engine reads. Also accepts pasted WKT as a fallback.
 *
 * @FieldWidget(
 *   id = "hoa_boundary_map",
 *   label = @Translation("HOA boundary map (Google draw)"),
 *   field_types = {"geofield"}
 * )
 */
final class HoaBoundaryMapWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $value = $items[$delta]->value ?? '';
    $mapId = Html::getUniqueId('hoa-boundary-map');
    $key = (string) (\Drupal::config('geofield_map.settings')->get('gmap_api_key') ?: '');

    $element['value'] = [
      '#type' => 'textarea',
      '#title' => $this->fieldDefinition->getLabel(),
      '#description' => $this->t('Draw the boundary on the map above (polygon tool), or paste WKT here. Member homes inside a discounted HOA boundary are auto-flagged for the winterizing discount.'),
      '#default_value' => $value,
      '#rows' => 2,
      '#prefix' => '<div class="hoa-boundary-map" data-hoa-map-id="' . $mapId . '" style="height:420px;width:100%;max-width:840px;border:1px solid #ccc;border-radius:6px;margin-bottom:.5rem"></div>',
      '#attributes' => [
        'class' => ['hoa-boundary-wkt'],
        'data-hoa-map' => $mapId,
      ],
      '#attached' => [
        'library' => ['bos_hoa/boundary_map'],
        'drupalSettings' => ['bosHoa' => ['gmapKey' => $key]],
      ],
    ];
    return $element;
  }

}
