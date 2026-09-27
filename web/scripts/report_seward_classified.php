<?php

declare(strict_types=1);

/**
 * READ-ONLY. Classified report of CURRENT (non-revision) content referencing the
 * former S&E Ward's name, with a present/historical tense guess, plus a separate
 * sewardsdevel.com list. Changes nothing.
 */

$db = \Drupal::database();
$NEEDLES = ['S&E Ward', 'S&amp;E Ward', 'S & E Ward', 'S&E Wards', 'SE Ward', "Ward's Landscape", 'Ward’s Landscape', 'Ward&#039;s Landscape', 'Wards Landscape'];
$HIST = '/(continuing|formerly|legacy|prior to|acquisition|acquired|founded|used to|previously|renamed|was\s|were\s|since 19)/i';
$FALSE = '/howards|edwards|awards|towards|stewards|backwards|forwards/i';

$whereFor = fn(string $col) => implode(' OR ', array_map(fn($i) => "`$col` LIKE :n$i", array_keys($NEEDLES)));
$mkArgs = fn() => array_combine(array_map(fn($i) => ":n$i", array_keys($NEEDLES)), array_map(fn($n) => "%$n%", $NEEDLES));

$snip = function ($v) use ($NEEDLES) {
  $v = (string) $v; $lc = mb_strtolower($v); $pos = PHP_INT_MAX;
  foreach ($NEEDLES as $n) { $p = mb_strpos($lc, mb_strtolower($n)); if ($p !== FALSE) { $pos = min($pos, $p); } }
  $start = $pos === PHP_INT_MAX ? 0 : max(0, $pos - 70);
  return trim(preg_replace('/\s+/', ' ', strip_tags(mb_substr($v, $start, 230))));
};
$classify = fn($t) => preg_match($FALSE, $t) ? 'FALSE-POSITIVE?' : (preg_match($HIST, $t) ? 'HISTORICAL (keep)' : 'PRESENT (replace)');

// [table, value_col, id_col, entity_type-or-null, label]
$SURFACES = [
  ['taxonomy_term_field_data', 'description__value', 'tid', 'taxonomy_term', 'Term core description'],
  ['taxonomy_term__field_description', 'field_description_value', 'entity_id', 'taxonomy_term', 'Term field_description'],
  ['taxonomy_term__field_service_public_desc', 'field_service_public_desc_value', 'entity_id', 'taxonomy_term', 'Service public desc'],
  ['taxonomy_term__field_service_crew_desc', 'field_service_crew_desc_value', 'entity_id', 'taxonomy_term', 'Service crew desc'],
  ['taxonomy_term__field_teammate_description', 'field_teammate_description_value', 'entity_id', 'taxonomy_term', 'Term teammate desc'],
  ['taxonomy_term__field_public_description', 'field_public_description_value', 'entity_id', 'taxonomy_term', 'Term public desc'],
  ['node__body', 'body_value', 'entity_id', 'node', 'Node body'],
  ['block_content__field_fl_lineage', 'field_fl_lineage_value', 'entity_id', NULL, 'Footer lineage block'],
  ['site_content__field_content_text', 'field_content_text_value', 'entity_id', NULL, 'site_content'],
  ['county__field_county_description', 'field_county_description_value', 'entity_id', NULL, 'County description'],
  ['handbook__field_intro', 'field_intro_value', 'entity_id', NULL, 'Handbook intro'],
  ['config_pages__field_estimate_disclosure', 'field_estimate_disclosure_value', 'entity_id', NULL, 'Estimate disclosure'],
  ['config_pages__field_chemical_license_info', 'field_chemical_license_info_value', 'entity_id', NULL, 'Chemical license info'],
  ['client_app__field_directions', 'field_directions_value', 'entity_id', NULL, 'client_app directions'],
  ['equipment__field_public_description', 'field_public_description_value', 'entity_id', 'equipment', 'Equipment public desc'],
  ['profile__field_company_name', 'field_company_name_value', 'entity_id', NULL, 'Profile company name'],
  ['profile__field_teammate_bio', 'field_teammate_bio_value', 'entity_id', NULL, 'Profile teammate bio'],
  ['properties__field_property_description', 'field_property_description_value', 'entity_id', NULL, 'Property description'],
  ['users_field_data', 'name', 'uid', 'user', 'User name'],
  ['contacts__field_last_name', 'field_last_name_value', 'entity_id', NULL, 'Contact last name'],
];

foreach ($SURFACES as [$tbl, $col, $idc, $etype, $label]) {
  if (!$db->schema()->tableExists($tbl)) { continue; }
  try { $rows = $db->query("SELECT `$idc` AS _id, `$col` AS _v FROM {" . $tbl . "} WHERE " . $whereFor($col), $mkArgs())->fetchAll(); }
  catch (\Throwable $e) { continue; }
  if (!$rows) { continue; }
  print "\n### $label  ($tbl) — " . count($rows) . " row(s)\n";
  foreach ($rows as $r) {
    $url = '';
    if ($etype) { try { $e = \Drupal::entityTypeManager()->getStorage($etype)->load($r->_id); if ($e) { try { $url = ' url=' . $e->toUrl()->toString(); } catch (\Throwable $x) {} } } catch (\Throwable $x) {} }
    $s = $snip($r->_v);
    printf("  [%-16s] id=%-6s%s\n      %s\n", $classify($s), $r->_id, $url, mb_substr($s, 0, 200));
  }
}

// material subheader — count only (2357, present tense, but material pages are out of scope per the brief)
$mc = $db->query("SELECT COUNT(*) FROM {material__field_subheader_text} WHERE field_subheader_text_value LIKE :q", [':q' => "%S&E Ward%"])->fetchField();
print "\n### Material subheader (material__field_subheader_text) — $mc rows, PRESENT tense ('…that we use at S&E Ward's Landscape Management'). NOTE: brief says material pages are out of scope → FLAGGED, not classified here.\n";

// sewardsdevel.com (report only) — current content/technical
print "\n\n========== sewardsdevel.com (REPORT ONLY) ==========\n";
foreach (['file_managed' => ['uri', 'fid'], 's3fs_file' => ['uri', NULL], 'redirect_404' => ['path', NULL], 'menu_link_content_data' => ['link__uri', 'id'], 'config' => ['data', 'name']] as $tbl => [$col, $idc]) {
  if (!$db->schema()->tableExists($tbl)) { continue; }
  try {
    $sel = $idc ? "`$idc` AS _id, `$col` AS _v" : "`$col` AS _v";
    $rows = $db->query("SELECT $sel FROM {" . $tbl . "} WHERE `$col` LIKE :q", [':q' => '%sewardsdevel%'])->fetchAll();
  }
  catch (\Throwable $e) { continue; }
  if ($rows) {
    printf("  %s.%s: %d\n", $tbl, $col, count($rows));
    foreach (array_slice($rows, 0, 8) as $r) { printf("      %s : %s\n", $r->_id ?? '', mb_substr((string) $r->_v, 0, 120)); }
  }
}
print "\nDONE (read-only).\n";
