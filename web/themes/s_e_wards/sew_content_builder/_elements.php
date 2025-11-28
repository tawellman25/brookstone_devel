<?php
function sewards_content_builder_set_elements(){
   return $shortcodes = array(
    'sew_column',
    'sew_row',
    'sew_accordion',
    'sew_box_color', 
    'sew_box_hover', 
    'sew_call_to_action',
    'sew_chart',
    'sew_code',
    'sew_text',
    'sew_text_noeditor',
    'sew_counter',
    'sew_drupal_block',
    'sew_heading',
    'sew_icon_box_classic',
    'sew_icon_box_color',
    'sew_image',
    'sew_our_team',
    'sew_pricing_item',
    'sew_progress',
    'sew_tabs',
    'sew_tabs_content',
    'sew_video_box',
    'sew_gmap',
    'sew_button',
    'sew_view',
    'sew_quote_text',
    'sew_image_content',
    'sew_image_content_parallax',
    'sew_gallery',
    'sew_our_partners',
    'sew_download',
    'sew_socials',
    'sew_instagram',
    'sew_text_rotate',
    'sew_quotes_rotator',
    'sew_links',
    'sew_work_process',
    'sew_job_box'
  );
}

function sewards_merge_atts( $pairs, $atts, $shortcode = '' ) {
    $atts = (array)$atts;
    $out = array();
    foreach($pairs as $name => $default) {
        if ( array_key_exists($name, $atts) )
            $out[$name] = $atts[$name];
        else
            $out[$name] = $default;
    }
    return $out;
}