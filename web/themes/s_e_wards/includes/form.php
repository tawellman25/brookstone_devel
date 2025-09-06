<?php
use Drupal\Core\Template\Attribute;

function s_e_wards_form_views_exposed_form_alter(array &$form) {
  //You need to verify the id
  global $base_url;
  
  $form['sort_by']['#weight'] = '-3';
  $form['sort_order']['#weight'] = '-2';

  $form['title']['#attributes']['placeholder'] = $form['#info']['filter-title']['label'];
  unset($form['#info']['filter-title']['label']);

  foreach ($form['#info'] as $filter_info) {
    $filter = $filter_info['value'];
    if ($form[$filter]['#type'] == 'select') {
      $form[$filter]['#options']['All'] = $filter_info['label'];
      unset($form['#info']['filter-' . $filter]['label']);
    }
  }
  
  $listing_search_action = 'listings';
  $language =  \Drupal::languageManager()->getCurrentLanguage()->getID();
  $languagesAll =  \Drupal::languageManager()->getLanguages();
  if(count($languagesAll) > 1){
    if(theme_get_setting('listing_search_action' . $language)){
      $listing_search_action = theme_get_setting('listing_search_action' . $language);
    }
  }else{
    if(theme_get_setting('listing_search_action')){
      $listing_search_action = theme_get_setting('listing_search_action');
    }
  }

  switch ($form['#id']) {
    case 'views-exposed-form-listing-content-listing-filter-form':
      $form['#action'] = base_path() . $listing_search_action;
      break;
  }
}

/**
 * Implements hook_form_alter() to add classes to the search form.
 */
function s_e_wards_form_alter(&$form, \Drupal\Core\Form\FormStateInterface $form_state, $form_id) {
  if (in_array($form_id, ['search_block_form', 'search_form'])) {
    $key = ($form_id == 'search_block_form') ? 'actions' : 'basic';
    if (!isset($form[$key]['submit']['#attributes'])) {
      $form[$key]['submit']['#attributes'] = new Attribute();
    }
    $form[$key]['submit']['#attributes']->addClass('search-form__submit');
  }
  //require_once('modules/devel/kint/kint/Kint.class.php');
  //Kint::dump($form);
  if($form_id == 'node_listing_form'){
    $form['status']['widget']['value']['#default_value'] = 0;
  }
}


