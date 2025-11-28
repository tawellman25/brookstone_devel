<?php 
if(!class_exists('element_sew_code')):
   class element_sew_code{
      public function render_form(){
         $fields = array(
            'type'      => 'gsc_code',
            'title'  => t('Code'), 
            'fields' => array(
               array(
                  'id'     => 'content',
                  'type'      => 'textarea',
                  'title'  => t('Content'),
               ),
            ),                                       
         );
         return $fields;
      } 
      
      public function render_content( $item ) {
         extract(sewards_merge_atts(array(
            'content'         => ''
         ), $attr));
         $output  = '<pre>';
            $output .= $content;
         $output .= '</pre>'."\n";
         return $output;
      }
   }
endif;

