<?php

declare(strict_types=1);

/**
 * The public credentials page: /about-us/credentials, as a VIEW of the credential
 * entity (spec 2026-09-28, which supersedes the static Credentials Page Copy).
 *
 * No number, date, carrier or limit appears in static copy anywhere — the header
 * and footer carry the argument, each record carries its own
 * field_public_description, and every fact is rendered from a field. That is the
 * whole point: nothing is maintained twice.
 *
 * Filter: field_publish_publicly = TRUE. The credential NUMBER is additionally
 * governed by field access (bos_credential), which shows it publicly only when the
 * TYPE's field_number_is_public is TRUE — so a policy number can never appear even
 * if somebody flags the record. Verified.
 *
 * The verification link comes from the credential TYPE, so the display carries a
 * relationship to the type term.
 *
 * Deliberately absent, per spec: expiration dates (an expired date on a live page
 * is worse than none; the verification link pushes freshness to the issuing agency)
 * and documents (office-only).
 *
 * Idempotent; saved through the View ENTITY so postSave registers the route.
 *   drush php:script web/scripts/build_credentials_public_page.php
 */

use Drupal\views\Entity\View;

$view = View::load('credentials');
if (!$view) {
  print "ERROR: view 'credentials' not found — run build_credential_views.php first.\n";
  return;
}

$HEADER = <<<'HTML'
<p>Colorado does not issue a statewide landscape contractor licence. Anyone with a truck and a trailer can put a magnet on the door and call themselves a landscaper, and in this valley a fair number do, usually for about one season.</p>

<p>So the only real way to tell a permanent company from a temporary one is to check the licences that <em>are</em> required — for spraying, for backflow testing, for running commercial trucks — and to ask to see the insurance certificate.</p>

<p>Here is ours. Every number on this page is public record, and the verification links go to the issuing agencies rather than back to us.</p>

<p><a class="button" href="/contact?c=creddocs">Request a Certificate of Insurance</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$FOOTER = <<<'HTML'
<h2>Why the insurance matters more than the licences</h2>

<p>This is the part that costs a homeowner real money when it is missing.</p>

<p>If an uninsured worker is injured on your property, the claim can land on your homeowner's policy. If an uninsured company backs a trailer into your garage, cuts a buried utility line, or drops a limb through your roof, you are the one holding it. Neither of those is a small number, and neither is hypothetical — both happen every season somewhere in this valley.</p>

<p>Ask any contractor for a certificate of insurance before work starts. A company that hesitates, or says they will send it later, has told you the answer.</p>

<h2>For HOA boards, property managers and commercial bids</h2>

<p>We will send a current certificate of insurance, copies of any licence on this page, and a W-9, on request. If your association or management company requires specific coverage limits, additional insured language, or a particular certificate holder, tell us what you need and we will have the carrier issue it.</p>

<p><a class="button" href="/contact?c=creddocs">Request Documents</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

/** Full field default set — a sparse Views field definition crashes on RENDER. */
$FD = [
  'label' => '', 'exclude' => FALSE,
  'alter' => [
    'alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE,
    'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE,
    'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '',
    'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE,
    'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE,
    'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE,
  ],
  'element_type' => '', 'element_class' => '', 'element_label_type' => '', 'element_label_class' => '',
  'element_label_colon' => TRUE, 'element_wrapper_type' => '', 'element_wrapper_class' => '',
  'element_default_classes' => TRUE, 'empty' => '', 'hide_empty' => TRUE, 'empty_zero' => FALSE,
  'hide_alter_empty' => TRUE, 'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE,
  'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE, 'delta_first_last' => FALSE,
  'multi_type' => 'separator', 'separator' => ', ', 'field_api_classes' => FALSE,
  'click_sort_column' => 'value', 'settings' => [], 'type' => 'string',
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'field',
];

$f = fn(string $name, string $label, string $type = 'string', array $extra = []) => [
  'id' => $name, 'table' => 'credential__' . $name, 'field' => $name,
  'entity_type' => 'credential', 'entity_field' => $name,
  'label' => $label, 'type' => $type, 'element_class' => 'credential-public__' . str_replace('field_', '', $name),
] + $extra + $FD;

$fields = [
  'title' => [
    'id' => 'title', 'table' => 'credential_field_data', 'field' => 'title',
    'entity_type' => 'credential', 'entity_field' => 'title', 'label' => '',
    'type' => 'string', 'settings' => ['link_to_entity' => FALSE],
    'element_type' => 'h2', 'element_class' => 'credential-public__name',
    'element_label_colon' => FALSE,
  ] + $FD,
  'field_credential_number' => $f('field_credential_number', 'Licence number'),
  'field_issuing_authority' => $f('field_issuing_authority', 'Issued by'),
  'field_coverage_limits' => $f('field_coverage_limits', 'Coverage'),
  'field_public_description' => $f('field_public_description', '', 'text_default'),
  // From the credential TYPE, via the relationship below.
  'field_verification_url' => [
    'id' => 'field_verification_url', 'table' => 'taxonomy_term__field_verification_url',
    'field' => 'field_verification_url', 'relationship' => 'field_credential_type',
    'entity_type' => 'taxonomy_term', 'entity_field' => 'field_verification_url',
    'label' => '', 'type' => 'link', 'element_class' => 'credential-public__verify',
  ] + $FD,
];

$display = $view->get('display');
$display['page_public'] = [
  'id' => 'page_public',
  'display_title' => 'Public credentials page',
  'display_plugin' => 'page',
  'position' => 6,
  'display_options' => [
    'defaults' => [
      'title' => FALSE, 'fields' => FALSE, 'filters' => FALSE, 'sorts' => FALSE,
      'style' => FALSE, 'row' => FALSE, 'pager' => FALSE, 'access' => FALSE,
      'relationships' => FALSE, 'header' => FALSE, 'footer' => FALSE, 'empty' => FALSE,
      'css_class' => FALSE,
    ],
    'title' => 'Licensing, Insurance & Credentials',
    'path' => 'about-us/credentials',
    'css_class' => 'credential-public',
    'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
    'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
    'style' => ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => 'credential-public__item', 'default_row_class' => TRUE, 'uses_fields' => FALSE]],
    'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => TRUE]],
    'relationships' => [
      'field_credential_type' => [
        'id' => 'field_credential_type', 'table' => 'credential__field_credential_type',
        'field' => 'field_credential_type', 'relationship' => 'none', 'group_type' => 'group',
        'admin_label' => 'Credential type term', 'plugin_id' => 'standard', 'required' => FALSE,
        'entity_type' => 'credential', 'entity_field' => 'field_credential_type',
      ],
    ],
    'fields' => $fields,
    'filters' => [
      'type' => [
        'id' => 'type', 'table' => 'credential_field_data', 'field' => 'type',
        'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'credential',
        'entity_field' => 'type', 'plugin_id' => 'bundle', 'operator' => 'in',
        'value' => ['credential' => 'credential'], 'group' => 1,
      ],
      'field_publish_publicly_value' => [
        'id' => 'field_publish_publicly_value', 'table' => 'credential__field_publish_publicly',
        'field' => 'field_publish_publicly_value', 'relationship' => 'none', 'group_type' => 'group',
        'admin_label' => '', 'entity_type' => 'credential', 'entity_field' => 'field_publish_publicly',
        'plugin_id' => 'boolean', 'operator' => '=', 'value' => '1', 'group' => 1,
        'exposed' => FALSE, 'is_grouped' => FALSE,
      ],
    ],
    'sorts' => [
      'field_list_order_value' => [
        'id' => 'field_list_order_value', 'table' => 'credential__field_list_order',
        'field' => 'field_list_order_value', 'relationship' => 'none', 'group_type' => 'group',
        'admin_label' => '', 'entity_type' => 'credential', 'entity_field' => 'field_list_order',
        'plugin_id' => 'standard', 'order' => 'ASC',
      ],
    ],
    'header' => [
      'area' => [
        'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none',
        'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text', 'empty' => TRUE,
        'content' => ['value' => $HEADER, 'format' => 'full_html'], 'tokenize' => FALSE,
      ],
    ],
    'footer' => [
      'area' => [
        'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none',
        'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text', 'empty' => TRUE,
        'content' => ['value' => $FOOTER, 'format' => 'full_html'], 'tokenize' => FALSE,
      ],
    ],
    'empty' => [
      'area_text_custom' => [
        'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom',
        'relationship' => 'none', 'group_type' => 'group', 'plugin_id' => 'text_custom',
        'empty' => TRUE,
        'content' => '<p>Our current licence and insurance documents are available on request — call <a href="tel:9708359661">970-835-9661</a>.</p>',
      ],
    ],
    'display_extenders' => [
      'metatag_display_extender' => [
        'metatags' => [
          'title' => 'Licensing, Insurance & Credentials | Brookstone Outdoors',
          'description' => "Colorado pesticide applicator licence, ABPA backflow certification, USDOT registered fleet, and full liability and workers' compensation coverage. Verifiable.",
        ],
      ],
    ],
  ],
];

$view->set('display', $display);
$view->save();

print "built display page_public at /about-us/credentials\n";
print "  filter: field_publish_publicly = 1   sort: field_list_order ASC\n";
print "  relationship to the credential type supplies field_verification_url\n";
print "DONE.\n";
