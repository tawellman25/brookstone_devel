<?php

/**
 * @file
 * Functions to support theming in the SASS Starterkit subtheme.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter() for settings form.
 *
 * Replace Barrio setting options with subtheme ones.
 *
 * Example on how to alter theme settings form
 */
function multi_pro_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state)
{

  // /* Color settings */


  /* Core theme  settings */
  $form['logo']['#group'] = 'visibility';
  $form['logo']['#title'] = t('Logo Image');
  $form['logo']['#weight'] = -995;
  $form['favicon']['#group'] = 'visibility';
  $form['favicon']['#weight'] = -994;

  $form['logo']['#open'] = TRUE;
  $form['favicon']['#open'] = TRUE;
  unset($form['theme_settings']);
  unset($form['bootstrap_barrio_source']);

  $form['visibility'] = [
    '#type' => 'vertical_tabs',
    '#prefix' => '<h2><small>' . t('Theme settings') . '</small></h2>',
    '#weight' => -999,
  ];
  // MULTI PRO SETTINGS
  $form['general'] = [
    '#type' => 'details',
    '#title' => t('General Options'),
    '#weight' => -999,
    '#group' => 'visibility',
    '#open' => FALSE,
  ];
  $form['header'] = [
    '#type' => 'details',
    '#title' => t('Header Options'),
    '#group' => 'visibility',
    '#open' => FALSE,
    '#weight' => -999,
  ];
  $form['footer'] = [
    '#type' => 'details',
    '#title' => t('Footer Options'),
    '#weight' => -999,
    '#group' => 'visibility',
    '#open' => FALSE,
  ];
  // LOADER
  $form['general']['loader-stricky'] = array(
    '#type' => 'fieldset',
    '#title' => t('Page Pre-Loader'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );
  $form['general']['loader-stricky']['loader'] = array(
    '#type'          => 'checkbox',
    '#title'         => t('For Pre-Loader'),
    '#default_value' => theme_get_setting('loader'),
  );
  //lOGIN PAGE SETTINGS
  $form['general']['login'] = array(
    '#type' => 'details',
    '#title' => t('Login Page Settings'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );
  $form['general']['login']['login_banner_title'] = array(
    '#type'  => 'textfield',
    '#title' => t('Banner Title'),
    '#description'   => t("Please enter the banner title of Login Page."),
    '#default_value' => theme_get_setting('login_banner_title'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['login']['login_banner_image'] = array(
    '#type' => 'managed_file',
    '#title' => t('Banner Image'),
    '#description' => t('Upload the Banner Image shown in login page'),
    '#default_value' => theme_get_setting('login_banner_image'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
    '#upload_location' => 'public://'
  );
  $form['general']['login']['login_page_title'] = array(
    '#type'  => 'textfield',
    '#title' => t('Page Title'),
    '#description'   => t("Please enter the Page title of Login Page."),
    '#default_value' => theme_get_setting('login_page_title'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['login']['login_image'] = array(
    '#type' => 'managed_file',
    '#title' => t('Image'),
    '#description' => t('Upload your Image shown in login page'),
    '#default_value' => theme_get_setting('login_image'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
    '#upload_location' => 'public://'
  );
  $form['general']['login']['login_name_help_text'] = array(
    '#type'  => 'textfield',
    '#title' => t('Name Help Text'),
    '#description'   => t("Please enter the help text to show under the user name input."),
    '#default_value' => theme_get_setting('login_name_help_text'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['login']['login_password_help_text'] = array(
    '#type' => 'textfield',
    '#title' => t('Password Help Text'),
    '#description' => t('Please enter the help text to show under the password input.'),
    '#default_value' => theme_get_setting('login_password_help_text'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  //REGISTER PAGE SETTINGS
  $form['general']['register'] = [
    '#type' => 'details',
    '#title' => 'Register Page Settings',
    '#collapssible' => TRUE,
    '#collapsed' => TRUE,
  ];
  $form['general']['register']['register_banner_title'] = array(
    '#type'  => 'textfield',
    '#title' => t('Banner Title'),
    '#description'   => t("Please enter the banner title of Register Page."),
    '#default_value' => theme_get_setting('register_banner_title'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['register']['register_banner_image'] = array(
    '#type' => 'managed_file',
    '#title' => t('Banner Image'),
    '#description' => t('Upload the Banner Image shown in Register page'),
    '#default_value' => theme_get_setting('register_banner_image'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
    '#upload_location' => 'public://'
  );
  $form['general']['register']['register_page_title'] = array(
    '#type'  => 'textfield',
    '#title' => t('Page Title'),
    '#description'   => t("Please enter the Page title of Register Page."),
    '#default_value' => theme_get_setting('register_page_title'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['register']['register_image'] = array(
    '#type' => 'managed_file',
    '#title' => t('Image'),
    '#description' => t('Upload your Image shown in Register page'),
    '#default_value' => theme_get_setting('register_image'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
    '#upload_location' => 'public://'
  );
  $form['general']['register']['register_email_help_text'] = array(
    '#type'  => 'textfield',
    '#title' => t('Email Help Text'),
    '#description'   => t("Please enter the help text to show under the user Email input."),
    '#default_value' => theme_get_setting('register_email_help_text'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['register']['register_name_help_text'] = array(
    '#type' => 'textfield',
    '#title' => t('Name Help Text'),
    '#description' => t('Please enter the help text to show under the Name input.'),
    '#default_value' => theme_get_setting('register_name_help_text'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  //PASSWORD RESET SETTINGS
  $form['general']['password_reset'] = array(
    '#type' => 'details',
    '#title' => 'Password Reset Settings',
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );
  $form['general']['password_reset']['pass_banner_title'] = array(
    '#type' => 'textfield',
    '#title' => 'Banner Title',
    '#description' => 'Enter the Banner Title Shown in the Password reset page',
    '#default_value' => theme_get_setting('pass_banner_title'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );
  $form['general']['password_reset']['pass_banner_image'] = array(
    '#type' => 'managed_file',
    '#title' => 'Banner Image',
    '#description' => 'Upload the Banner Image Shown in the Password reset page',
    '#default_value' => theme_get_setting('pass_banner_image'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
    '#upload_location' => 'public://',
  );
  $form['general']['password_reset']['pass_page_title'] = array(
    '#type'  => 'textfield',
    '#title' => t('Page Title'),
    '#description'   => t("Please enter the Page title of Password Reset Page."),
    '#default_value' => theme_get_setting('pass_page_title'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['password_reset']['pass_image'] = array(
    '#type' => 'managed_file',
    '#title' => 'Image',
    '#description' => 'Upload the Image Shown in the Password reset page',
    '#default_value' => theme_get_setting('pass_image'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
    '#upload_location' => 'public://',
  );
  $form['general']['password_reset']['pass_email_help_text'] = array(
    '#type' => 'textfield',
    '#title' => t('Email Help Text'),
    '#description' => t('Please enter the help text to show under the Email input.'),
    '#default_value' => theme_get_setting('pass_email_help_text'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  //COMING SOON AND MAINTENANCE PAGE SETTINGS
  $form['general']['maintenance_mode'] = array(
    '#type' => 'details',
    '#title' => 'Maintenance Mode and Coming Soon settings',
    '#collapsed' => TRUE,
    '#collapsible' => TRUE,
  );
  $form['general']['maintenance_mode']['mode_type'] = array(
    '#type' => 'select',
    '#options' => array('1' => 'Maintenance Mode', '2' => 'Coming Soon'),
    "#default_value" => theme_get_setting('mode_type'),
    '#description' => 'Please select any one mode to change the content of Maintanence page. If Coming soon mode selected, while site under Maintanence, Coming Soon page content will be displayed
    ',
  );
  $form['general']['maintenance_mode']['maintenance_mode_title'] = array(
    '#type'  => 'textfield',
    '#title' => t('Maintenance Mode Title'),
    '#description'   => t("Please enter the maintenance title of Maintenance mode Page."),
    '#default_value' => theme_get_setting('maintenance_mode_title'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['maintenance_mode']['maintenance_mode_description'] = array(
    '#type'  => 'textarea',
    '#title' => t('Maintenance Mode Description'),
    '#description'   => t("Please enter the maintenance description of Maintenance mode Page."),
    '#default_value' => theme_get_setting('maintenance_mode_description'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['maintenance_mode']['background_image_maintenance'] = array(
    '#type' => 'managed_file',
    '#title' => t('Background Image'),
    '#default_value' => theme_get_setting('background_image_maintenance'),
    '#description' => t('Choose background image for maintenance page'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
    '#upload_location' => 'public://'
  );
  //Coming Soon settings
  $form['general']['maintenance_mode']['coming_soon_title'] = array(
    '#type'  => 'textfield',
    '#title' => t('Coming Soon Title'),
    '#description'   => t("Please enter the Title of Coming Soon page"),
    '#default_value' => theme_get_setting('coming_soon_title'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['maintenance_mode']['coming_soon_description'] = array(
    '#type'  => 'textarea',
    '#title' => t('Coming Soon Description'),
    '#description'   => t("Please enter the description for coming soon page"),
    '#default_value' => theme_get_setting('coming_soon_description'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['general']['maintenance_mode']['background_image_coming_soon'] = array(
    '#type' => 'managed_file',
    '#title' => t('Background Image'),
    '#default_value' => theme_get_setting('background_image_coming_soon'),
    '#description' => t('Choose background image for Coming Soon page'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
    '#upload_location' => 'public://',
  );
  $form['general']['maintenance_mode']['date'] = [
    '#type' => 'date',
    '#title' => t('Launch Date'),
    '#description' => t('Please enter the date of site coming to alive, This date will be displayed in Coming soon page'),
    '#default_value' => theme_get_setting('date'),
  ];
  $form['general']['maintenance_mode']['custom_message'] = [
    '#type' => 'textfield',
    '#title' => t('Date expired custom message'),
    '#description' => t('Please enter the text to show message instead of countdown (This text will replace the countdown when date expired)'),
    '#default_value' => theme_get_setting('custom_message'),
  ];
  // SEARCH PAGE
  $form['general']['search'] = array(
    '#type' => 'details',
    '#title' => t('Search Page'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );
  $form['general']['search']['search_banner_title'] = [
    '#type'          => 'textfield',
    '#title'         => t('Search Banner Title'),
    '#default_value' => theme_get_setting('search_banner_title'),
    '#description'   => t("Please enter the title of search Page."),
  ];
  $form['general']['search']['search_banner_image'] = [
    '#type' => 'managed_file',
    '#title'    => t('Banner Image'),
    '#default_value' => theme_get_setting('search_banner_image'),
    '#upload_location' => 'public://',
    '#description' => t('Choose banner image for Search pages'),
  ];

  //HEADER OPTIONS
  $form['header']['header_style'] = array(
    '#type' => 'select',
    '#options' => array(
      'header-1' => 'Header Style 1',
      'header-2' => 'Header Style 2',
      'header-3' => 'Header Style 3',
    ),
    '#title' => t('Header style'),
    '#default_value' => theme_get_setting('header_style'),
    '#description' => t("Select The Header Style"),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['header']['sticky'] = array(
    '#type' => 'checkbox',
    '#title' => 'Stick Menu',
    '#default_value' => theme_get_setting('sticky'),
  );
  $form['header']['phone'] = array(
    '#type'  => 'textfield',
    '#title' => t('Phone Number'),
    '#description'   => t("Please Enter the Phone Number text for Header"),
    '#default_value' => theme_get_setting('phone'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['header']['phone_number'] = array(
    '#type'  => 'textfield',
    '#title' => t('Phone Number'),
    '#description'   => t("Please Enter the Phone Number without spaces for Header"),
    '#default_value' => theme_get_setting('phone_number'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['header']['mail'] = array(
    '#type'  => 'textfield',
    '#title' => t('Mail Id'),
    '#description'   => t("Please Enter the Mail Id for Header"),
    '#default_value' => theme_get_setting('mail'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['header']['button'] = array(
    '#type'  => 'textfield',
    '#title' => t('Button in Header Style 2'),
    '#description'   => t("Enter the link of Button"),
    '#default_value' => theme_get_setting('button'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['header']['button_text'] = array(
    '#type'  => 'textfield',
    '#title' => t('button_text'),
    '#description'   => t("Enter the Button Text"),
    '#default_value' => theme_get_setting('button_text'),
    '#collapsible' => TRUE,
    '#collapsed' => False,
  );
  $form['color_options'] = array(
    '#type' => 'details',
    '#title' => t('Color Options'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
    '#group' => 'visibility',
    '#weight' => -996,
  );
  $form['color_options']['default_color'] = [
    '#type' => 'checkbox',
    '#title' => t('Use the Default Color'),
    '#default_value' => theme_get_setting('default_color'),
    '#tree' => FALSE,
  ];
  $form['color_options']['color_settings'] = [
    '#type' => 'container',
    '#states' => [
      // Hide the color settings when using the default color.
      'invisible' => [
        'input[name="default_color"]' => ['checked' => TRUE],
      ],
    ],
  ];
  $form['color_options']['color_settings']['primary_color'] = [
    '#type' => 'color',
    '#title' => t('Select Primary Color'),
    '#default_value' => theme_get_setting('primary_color'),
  ];
  $form['color_options']['color_settings']['secondary_color'] = [
    '#type' => 'color',
    '#title' => t('Select Secondary Color'),
    '#default_value' => theme_get_setting('secondary_color'),
  ];
  // Custom CSS
  $form['custom_css'] = array(
    '#type' => 'details',
    '#title' => t('Custom CSS'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
    '#group' => 'visibility',
    '#open' => FALSE,
    '#weight' => -997,
  );
  $form['custom_css']['styles'] = array(
    '#type'          => 'textarea',
    '#title'         => t('Custom Style'),
    '#default_value' => theme_get_setting('styles'),
    '#description'   => t("Place your custom style for your site."),
  );

  $form['#submit'][] = 'multi_pro_form_submit';
}
function multi_pro_form_submit(&$form, $form_state)
{

  // Login - Banner Image
  if ($file_id = $form_state->getValue(['login_banner_image', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
  // Login - Image
  if ($file_id = $form_state->getValue(['login_image', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
  // Register - Banner Image
  if ($file_id = $form_state->getValue(['register_banner_image', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
  // Register - Image
  if ($file_id = $form_state->getValue(['register_image', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
  // Reset Password Banner Image
  if ($file_id = $form_state->getValue(['pass_banner_image', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
  // Reset Password - Image
  if ($file_id = $form_state->getValue(['pass_image', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
  // Maintenance - BG Image
  if ($file_id = $form_state->getValue(['background_image_maintenance', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
  // Coming Soon - BG Image
  if ($file_id = $form_state->getValue(['background_image_coming_soon', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
  // Search - Banner Image
  if ($file_id = $form_state->getValue(['search_banner_image', '0'])) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    $file->setPermanent();
    $file->save();
  }
}
