<?php

/**
 * Implements hook_views_data().
 */
function bos_spray_route_ui_views_data() {
  $data['property_spraying_info_field_data']['weed_spray_days_field'] = [
    'title' => t('Days Since Last Applied'),
    'help' => t('Computed field showing days since last weed spray with color status indicator.'),
    'field' => [
      'id' => 'weed_spray_days_field',
    ],
  ];
  return $data;
}
