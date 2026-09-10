<?php

/**
 * @file
 * Sitewide footer — structure (block_content types + fields). Idempotent,
 * entity-API (the BOS deploy idiom; run per env). Content instances, menus,
 * the promo view and block placements are set up by the companion scripts.
 *
 *   ddev drush php:script web/scripts/setup_footer_structure.php   (dev)
 *   drush php:script web/scripts/setup_footer_structure.php        (live)
 */

use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/** Create a block_content type if missing. */
function _footer_bct(string $id, string $label, string $desc): void {
  if (!BlockContentType::load($id)) {
    BlockContentType::create(['id' => $id, 'label' => $label, 'description' => $desc, 'revision' => FALSE])->save();
    echo "• block type: {$id}\n";
  }
  else {
    echo "• block type {$id} exists\n";
  }
}

/** Create a field storage + instance on a block_content bundle if missing. */
function _footer_field(string $bundle, string $field, string $type, string $label, array $storage_settings = [], array $field_settings = [], bool $required = FALSE): void {
  if (!FieldStorageConfig::loadByName('block_content', $field)) {
    FieldStorageConfig::create([
      'field_name' => $field,
      'entity_type' => 'block_content',
      'type' => $type,
      'settings' => $storage_settings,
      'cardinality' => 1,
    ])->save();
  }
  if (!FieldConfig::loadByName('block_content', $bundle, $field)) {
    FieldConfig::create([
      'field_name' => $field,
      'entity_type' => 'block_content',
      'bundle' => $bundle,
      'label' => $label,
      'required' => $required,
      'settings' => $field_settings,
    ])->save();
    echo "  + {$bundle}.{$field}\n";
  }
}

// ---- Company / NAP ----
_footer_bct('footer_company', 'Footer: Company / NAP', 'Name, address, phone, email, hours + Facebook shown in footer column 1.');
_footer_field('footer_company', 'field_fc_name', 'string', 'Business name');
_footer_field('footer_company', 'field_fc_tagline', 'string', 'Tagline');
_footer_field('footer_company', 'field_fc_address', 'string_long', 'Address (verbatim, matches Google Business Profile)');
_footer_field('footer_company', 'field_fc_phone', 'string', 'Phone');
_footer_field('footer_company', 'field_fc_email', 'email', 'Email');
_footer_field('footer_company', 'field_fc_hours', 'string_long', 'Office hours');
_footer_field('footer_company', 'field_fc_facebook', 'link', 'Facebook URL', [], ['title' => 0]);
_footer_field('footer_company', 'field_fc_maps_url', 'link', 'Google Maps directions URL', [], ['title' => 0]);

// ---- Service Area ----
_footer_bct('footer_service_area', 'Footer: Service Area', 'Counties + town list shown in footer column 4 (plain text, no links).');
_footer_field('footer_service_area', 'field_fsa_heading', 'string', 'Heading');
_footer_field('footer_service_area', 'field_fsa_body', 'string_long', 'Service area text');

// ---- Legal ----
_footer_bct('footer_legal', 'Footer: Legal', 'Lineage line for the legal bar. Copyright + year render dynamically in the template.');
_footer_field('footer_legal', 'field_fl_lineage', 'string_long', 'Lineage line');

// ---- Seasonal promo ----
_footer_bct('promo', 'Footer: Seasonal promo', 'Date-driven seasonal promo surfaced by the footer_promo view (one active at a time).');
_footer_field('promo', 'field_promo_heading', 'string', 'Heading');
_footer_field('promo', 'field_promo_body', 'string_long', 'Body line');
_footer_field('promo', 'field_promo_btn_label', 'string', 'Button label');
_footer_field('promo', 'field_promo_btn_url', 'link', 'Button URL', [], ['title' => 0]);
_footer_field('promo', 'field_promo_from', 'datetime', 'Active from', ['datetime_type' => 'date']);
_footer_field('promo', 'field_promo_until', 'datetime', 'Active until', ['datetime_type' => 'date']);

echo "Footer structure done.\n";
