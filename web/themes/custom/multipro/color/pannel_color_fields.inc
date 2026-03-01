<?php

  $form['pannel_color'] = array(
    '#type' => 'details',
    '#title' => t('Color Panel Display Settings'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
    '#group' => 'visibility',
    '#open' => FALSE,
    '#weight' => -992,
  );
  $form['color_options'] = array(
  '#type' => 'value',
  '#value' => array('APPLICATION' => t('Application'),
                    'DEVELOPMENT' => t('Development'),
                    'ENHANCEMENT' => t('Enhancement'))
  );
  $form ['pannel_color']['show_pannel'] = array(
    '#type'          => 'checkbox',
    '#title'         => t('Show color pannel for Anonymous User'),
    '#default_value' => theme_get_setting('show_pannel'),
  );
  


?>