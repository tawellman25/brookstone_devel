<?php
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Url;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;

function s_e_wards_preprocess_menu__main(&$variables) {
  $variables['attributes']['class'][] = 'clearfix';

  foreach ($variables['items'] as &$item) {
   $menu_link_attributes = _s_e_wards_attributes_get_attributes($item['original_link']);

      if (count($menu_link_attributes)) {
        $url_attributes = $item['url']->getOption('attributes') ?: [];
        $attributes = array_merge($url_attributes, $menu_link_attributes);

        $item['url']->setOption('attributes', $attributes);
        $item['sew_block_content'] = '';
        $item['attributes']['sew_class'] = (isset($attributes['sew_class']) && $attributes['sew_class']) ? trim($attributes['sew_class']): '';
        $item['attributes']['sew_icon'] = (isset($attributes['sew_icon']) && $attributes['sew_icon']) ? trim($attributes['sew_icon']): '';
        $item['attributes']['sew_layout'] = (isset($attributes['sew_layout']) && $attributes['sew_layout']) ? $attributes['sew_layout']: '';
        $item['attributes']['sew_layout_columns'] = (isset($attributes['sew_layout_columns']) && $attributes['sew_layout_columns']) ? $attributes['sew_layout_columns']: 4;
        $item['attributes']['sew_block'] = (isset($attributes['sew_block']) && $attributes['sew_block']) ? $attributes['sew_block']: '';
        if(isset($attributes['sew_layout']) && $attributes['sew_layout']=='menu-block'){
          $item['sew_block_content'] = s_e_wards_render_block($attributes['sew_block']);
        }
     }
    if(isset($item['below'])){
      foreach ($item['below'] as &$item_level2) {
        $menu_link_attributes = _s_e_wards_attributes_get_attributes($item_level2['original_link']);
        if (count($menu_link_attributes)) {
          $url_attributes = $item_level2['url']->getOption('attributes') ?: [];
          $attributes = array_merge($url_attributes, $menu_link_attributes);

          $item_level2['url']->setOption('attributes', $attributes);
          $item_level2['sew_block_content'] = '';
          $item_level2['attributes']['sew_class'] = (isset($attributes['sew_class']) && $attributes['sew_class']) ? trim($attributes['sew_class']): '';
          $item_level2['attributes']['sew_icon'] = (isset($attributes['sew_icon']) && $attributes['sew_icon']) ? trim($attributes['sew_icon']): '';
          $item_level2['attributes']['sew_layout'] = (isset($attributes['sew_layout']) && $attributes['sew_layout']) ? $attributes['sew_layout']: '';
          $item_level2['attributes']['sew_layout_columns'] = (isset($attributes['sew_layout_columns']) && $attributes['sew_layout_columns']) ? $attributes['sew_layout_columns']: 4;
          $item_level2['attributes']['sew_block'] = (isset($attributes['sew_block']) && $attributes['sew_block']) ? $attributes['sew_block']: '';
          if(isset($attributes['sew_layout']) && $attributes['sew_layout']=='menu-block'){
            $item_level2['sew_block_content'] = sewards_monte_render_block($attributes['sew_block']);
          }
        }
      }
      }
   }
}

function _s_e_wards_attributes_get_attributes(MenuLinkInterface $menu_link_content_plugin) {
  $attributes = [];
  try {
    $plugin_id = $menu_link_content_plugin->getPluginId();
  }
  catch (PluginNotFoundException $e) {
    return $attributes;
  }
  if (strpos($plugin_id, ':') === FALSE) {
    return $attributes;
  }
  list($entity_type, $uuid) = explode(':', $plugin_id, 2);

  if ($entity_type == 'menu_link_content') {
    $entity = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties(['uuid' => $uuid]);
    if (count($entity)) {
      $entity_values = array_values($entity)[0];
      $options = $entity_values->link->first()->options;
      $attributes = isset($options['attributes']) ? $options['attributes'] : [];
    }
  }
  return $attributes;
}