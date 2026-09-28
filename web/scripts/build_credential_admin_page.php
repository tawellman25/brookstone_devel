<?php

declare(strict_types=1);

/**
 * Admin home for credentials at /admin/operations/system_content/credentials,
 * with Company and Expiring-soon as tabs beneath it (Todd 2026-09-28).
 *
 * Matches how every other view under admin/operations/system_content is wired:
 * menu `admin`, type `normal` (city/county/state/zipcodes, the spray-department
 * lists). The earlier /admin/office/credentials page is MOVED here rather than
 * left in place, so there is one admin home rather than two.
 *
 * page_admin inherits the DEFAULT display's fields deliberately — those already
 * carry the full Views field option set, so there is no chance of reintroducing
 * the sparse-definition render crash.
 *
 * Idempotent; saved through the View ENTITY so postSave rebuilds the routes and
 * local tasks.
 *   drush php:script web/scripts/build_credential_admin_page.php
 */

use Drupal\views\Entity\View;

$BASE = 'admin/operations/system_content/credentials';

$view = View::load('credentials');
if (!$view) {
  print "ERROR: view 'credentials' not found.\n";
  return;
}
$display = $view->get('display');

/* 1. The admin home — everything, inheriting the default field set. */
$display['page_admin'] = [
  'id' => 'page_admin',
  'display_title' => 'Credentials (admin)',
  'display_plugin' => 'page',
  'position' => 7,
  'display_options' => [
    'defaults' => ['title' => FALSE, 'sorts' => FALSE],
    'title' => 'Credentials',
    'path' => $BASE,
    'sorts' => [
      'field_list_order_value' => [
        'id' => 'field_list_order_value', 'table' => 'credential__field_list_order',
        'field' => 'field_list_order_value', 'relationship' => 'none', 'group_type' => 'group',
        'admin_label' => '', 'entity_type' => 'credential', 'entity_field' => 'field_list_order',
        'plugin_id' => 'standard', 'order' => 'ASC',
      ],
      'title' => [
        'id' => 'title', 'table' => 'credential_field_data', 'field' => 'title',
        'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'credential',
        'entity_field' => 'title', 'plugin_id' => 'standard', 'order' => 'ASC',
      ],
    ],
    'menu' => [
      'type' => 'normal',
      'title' => 'Credentials',
      'description' => 'Licences, certifications and insurance policies.',
      'weight' => 30,
      'menu_name' => 'admin',
      'parent' => '',
      'expanded' => FALSE,
    ],
  ],
];

/* 2. Move the company list + expiring list under it, as tabs. */
$display['page_company']['display_options']['path'] = $BASE . '/company';
$display['page_company']['display_options']['menu'] = [
  'type' => 'tab', 'title' => 'Company', 'description' => 'Company-wide credentials — the certificate-request list.',
  'weight' => 10, 'menu_name' => 'admin', 'parent' => '', 'expanded' => FALSE,
];

$display['page_expiring']['display_options']['path'] = $BASE . '/expiring';
$display['page_expiring']['display_options']['menu'] = [
  'type' => 'tab', 'title' => 'Expiring soon', 'description' => 'Credentials inside their renewal window, or already expired.',
  'weight' => 20, 'menu_name' => 'admin', 'parent' => '', 'expanded' => FALSE,
];

$view->set('display', $display);
$view->save();

printf("page_admin    -> /%s  (menu: admin/normal)\n", $BASE);
printf("page_company  -> /%s/company  (tab)\n", $BASE);
printf("page_expiring -> /%s/expiring (tab)\n", $BASE);
print "DONE.\n";
