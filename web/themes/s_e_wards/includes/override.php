<?php
function s_e_wards_preprocess_views_view_grid(&$variables) {
   $view = $variables['view'];
   $rows = $variables['rows'];
   $style = $view->style_plugin;
   $options = $style->options;
   $variables['sew_masonry']['class'] = '';
   $variables['sew_masonry']['class_item'] = '';
   if(strpos($options['row_class_custom'] , 'masonry') || $options['row_class_custom'] == 'masonry' ){
      $variables['sew_masonry']['class'] = 'post-masonry-style row';
      $variables['sew_masonry']['class_item'] = 'item-masory';
   }
}

function s_e_wards_preprocess_views_view_unformatted__taxonomy_term(&$variables){
   $current_uri = \Drupal::request()->getRequestUri();
   $url = \Drupal::service('path.current')->getPath();
   $arg = explode('/', $url);
   $tid = 0;
   $taxonomy_id = '';
   if ((isset($arg[1]) && $arg[1] ==  "taxonomy") && (isset($arg[2]) && $arg[2] == "term") && isset($arg[3]) && is_numeric($arg[3]) ) {
      $tid = $arg[3];
      $term = Drupal\taxonomy\Entity\Term::load($tid);
      if($term->bundle()){
         $taxonomy_id = $term->bundle();
      }
   }
   $variables['taxonomy_id'] = $taxonomy_id;
   
}