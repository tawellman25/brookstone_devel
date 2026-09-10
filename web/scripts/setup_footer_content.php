<?php

/**
 * @file
 * Sitewide footer — content (block_content instances), menus + links, and the
 * role-gated block placements. Idempotent; run per env (dev then live).
 *
 * All footer blocks are gated to `anonymous` + `client` roles only, so the
 * footer never renders on the crew/office brookstone_olivero app pages
 * (WO, schedule, etc.). Marketing pages (/, /winterize, /fall-cleanup) keep
 * their own walled footers — untouched.
 *
 *   ddev drush php:script web/scripts/setup_footer_content.php     (dev)
 *   drush php:script web/scripts/setup_footer_content.php          (live)
 */

use Drupal\block_content\Entity\BlockContent;
use Drupal\block\Entity\Block;
use Drupal\system\Entity\Menu;
use Drupal\menu_link_content\Entity\MenuLinkContent;

$THEME = 'brookstone_olivero';
$ROLES = ['anonymous' => 'anonymous', 'client' => 'client'];
$bcStorage = \Drupal::entityTypeManager()->getStorage('block_content');

/**
 * Create a block_content by info label, or SYNC its field values if it already
 * exists (keeping the same UUID so block placements stay valid). Syncing matters
 * because a block created before its fields existed would otherwise stay empty.
 */
function _foot_block(string $bundle, string $info, array $values): BlockContent {
  $existing = \Drupal::entityTypeManager()->getStorage('block_content')
    ->loadByProperties(['type' => $bundle, 'info' => $info]);
  if ($existing) {
    $bc = reset($existing);
    foreach ($values as $f => $v) {
      if ($bc->hasField($f)) { $bc->set($f, $v); }
    }
    $bc->save();
    echo "• synced block content: {$info}\n";
    return $bc;
  }
  $bc = BlockContent::create(['type' => $bundle, 'info' => $info, 'reusable' => TRUE] + $values);
  $bc->save();
  echo "• created block content: {$info}\n";
  return $bc;
}

/** Place a block (role-gated); update label/display/region/weight on re-run. */
function _foot_place(string $id, string $plugin, string $region, int $weight, array $roles, string $theme, string $label = '', string $label_display = '0'): void {
  $settings = ['id' => $plugin, 'label' => $label, 'label_display' => $label_display];
  $visibility = [
    'user_role' => [
      'id' => 'user_role',
      'roles' => $roles,
      'negate' => FALSE,
      'context_mapping' => ['user' => '@user.current_user_context:current_user'],
    ],
  ];
  if ($block = Block::load($id)) {
    $block->set('settings', $settings)->set('region', $region)->set('weight', $weight)->set('visibility', $visibility);
    $block->save();
    echo "  · updated: {$id}\n";
    return;
  }
  Block::create(['id' => $id, 'theme' => $theme, 'region' => $region, 'weight' => $weight, 'plugin' => $plugin, 'settings' => $settings, 'visibility' => $visibility])->save();
  echo "  · placed: {$id} ({$region} w{$weight})\n";
}

/** Create a menu if missing. */
function _foot_menu(string $id, string $label): void {
  if (!Menu::load($id)) { Menu::create(['id' => $id, 'label' => $label, 'description' => ''])->save(); echo "• menu: {$id}\n"; }
  else { echo "• menu {$id} exists\n"; }
}

/** Add a menu_link_content if missing (match by menu + uri). */
function _foot_link(string $menu, string $title, string $uri, int $weight): void {
  $found = \Drupal::entityTypeManager()->getStorage('menu_link_content')->getQuery()
    ->condition('menu_name', $menu)->condition('link.uri', $uri)->accessCheck(FALSE)->range(0, 1)->execute();
  if ($found) { echo "  · link exists: {$menu} {$title}\n"; return; }
  MenuLinkContent::create(['title' => $title, 'link' => ['uri' => $uri], 'menu_name' => $menu, 'weight' => $weight, 'expanded' => FALSE])->save();
  echo "  · link: {$menu} {$title} (w{$weight})\n";
}

// ---------------------------------------------------------------------------
// 1. Content blocks
// ---------------------------------------------------------------------------
$company = _foot_block('footer_company', 'Footer NAP', [
  'field_fc_name' => 'Brookstone Outdoors',
  'field_fc_tagline' => 'Creating and Maintaining Your Outdoor Spaces',
  'field_fc_address' => '20143 Austin Rd, Austin, CO 81410',
  'field_fc_phone' => '970-835-9661',
  'field_fc_email' => 'office@brookstoneoutdoors.com',
  'field_fc_hours' => "Monday – Thursday: 9:00 a.m. – 4:00 p.m.\nFriday: 9:00 a.m. – 12:00 p.m.",
  'field_fc_facebook' => ['uri' => 'https://www.facebook.com/profile.php?id=61593921431955'],
  'field_fc_maps_url' => ['uri' => 'https://www.google.com/maps/dir/?api=1&destination=Q2R7%2BQ2%20Austin%2C%20Orchard%20City%2C%20CO'],
]);
$area = _foot_block('footer_service_area', 'Footer Service Area', [
  'field_fsa_heading' => 'Service Area',
  'field_fsa_body' => "Delta and Montrose counties, Colorado\n\nCedaredge · Delta · Austin · Eckert · Orchard City · Hotchkiss · Paonia · Crawford · Olathe · Montrose",
]);
$legal = _foot_block('footer_legal', 'Footer Legal', [
  'field_fl_lineage' => "Continuing S&E Ward's Landscape Management, serving Western Colorado since 1995.",
]);

// Seasonal promos — dates set so exactly one (winterization) is active today.
$promos = [
  ['Sprinkler winterization is booking now.', 'Frozen water does not crack PVC, it shatters it. Every zone run twice with an 85 CFM compressor, and the re-fix is free if we miss one.', 'Schedule Winterization', 'internal:/winterize', '2026-09-01', '2026-11-30'],
  ['Snow and ice management contracts.', 'Residential drives, commercial lots and HOA common areas. Contracts signed before the first storm get scheduled first.', 'Ask About Snow Service', 'internal:/services/snow-removal', '2026-12-01', '2027-02-28'],
  ['Time to turn the sprinklers back on.', 'Spring startup, backflow testing and a full system check before the first hot week.', 'Schedule Spring Startup', 'internal:/services/sprinkler-system', '2027-03-01', '2027-05-31'],
  ['Planning a landscape project?', 'Design-build installations book months out. Start the conversation now for work this season or next.', 'Request an Estimate', 'internal:/request-estimate', '2027-06-01', '2027-08-31'],
];
foreach ($promos as $p) {
  _foot_block('promo', 'Promo: ' . $p[0], [
    'field_promo_heading' => $p[0],
    'field_promo_body' => $p[1],
    'field_promo_btn_label' => $p[2],
    'field_promo_btn_url' => ['uri' => $p[3]],
    'field_promo_from' => ['value' => $p[4]],
    'field_promo_until' => ['value' => $p[5]],
  ]);
}

// ---------------------------------------------------------------------------
// 2. Menus + links (Licensing omitted until /about/credentials exists)
// ---------------------------------------------------------------------------
_foot_menu('footer-services', 'Footer: Services');
$svc = [
  ['Landscape Design & Build', 'internal:/services/landscaping'],
  ['Lawn & Property Maintenance', 'internal:/services/landscape-lawn-care'],
  ['Irrigation & Sprinkler Systems', 'internal:/services/sprinkler-system'],
  ['Sprinkler Winterization', 'internal:/winterize'],
  ['Landscape Lighting', 'internal:/lighting'],
  ['Holiday Decorations', 'internal:/services/christmas-decorations'],
  ['Snow & Ice Management', 'internal:/services/snow-removal'],
  ['All Services', 'internal:/services'],
];
foreach ($svc as $i => $l) { _foot_link('footer-services', $l[0], $l[1], $i); }

_foot_menu('footer-company', 'Footer: Company');
$co = [
  ['About Us', 'internal:/about'],
  ['Careers', 'internal:/careers'],
  ['Contact', 'internal:/contact'],
  ['Request an Estimate', 'internal:/request-estimate'],
  ['Crew Login', 'internal:/user/login'],
];
foreach ($co as $i => $l) { _foot_link('footer-company', $l[0], $l[1], $i); }

_foot_menu('footer-legal', 'Footer: Legal');
// Privacy link added; Accessibility omitted until that page exists.
_foot_link('footer-legal', 'Privacy Policy', 'internal:/privacy', 0);

// ---------------------------------------------------------------------------
// 3. Placements (all role-gated anon + client). footer_top = columns + promo;
//    footer_bottom = legal.  DOM/weight order = mobile order (NAP, promo,
//    services, company, area); desktop layout via grid-template-areas in CSS.
// ---------------------------------------------------------------------------
_foot_place("{$THEME}_foot_company", 'block_content:' . $company->uuid(), 'footer_top', 0, $ROLES, $THEME);
_foot_place("{$THEME}_foot_promo", 'views_block:footer_promo-block_1', 'footer_top', 1, $ROLES, $THEME);
_foot_place("{$THEME}_foot_services", 'system_menu_block:footer-services', 'footer_top', 2, $ROLES, $THEME, 'Services', 'visible');
_foot_place("{$THEME}_foot_companylinks", 'system_menu_block:footer-company', 'footer_top', 3, $ROLES, $THEME, 'Company', 'visible');
_foot_place("{$THEME}_foot_servicearea", 'block_content:' . $area->uuid(), 'footer_top', 4, $ROLES, $THEME);
_foot_place("{$THEME}_foot_legal", 'block_content:' . $legal->uuid(), 'footer_bottom', 0, $ROLES, $THEME);
_foot_place("{$THEME}_foot_legallinks", 'system_menu_block:footer-legal', 'footer_bottom', 1, $ROLES, $THEME);

// ---------------------------------------------------------------------------
// 4. Clean up the stray "About" link left in the old (unrendered) `footer` menu
//    from the /about build — it now lives in footer-company.
// ---------------------------------------------------------------------------
$stray = \Drupal::entityTypeManager()->getStorage('menu_link_content')->getQuery()
  ->condition('menu_name', 'footer')->condition('link.uri', 'internal:/about')->accessCheck(FALSE)->execute();
foreach (\Drupal::entityTypeManager()->getStorage('menu_link_content')->loadMultiple($stray) as $s) {
  $s->delete();
  echo "• removed stray 'About' link from old footer menu\n";
}

echo "Footer content + placements done.\n";
