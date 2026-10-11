<?php
function bos_seasons_parse(string $seasonsMd, string $relocationMd): array {
  $out = ['landing' => [], 'terms' => [], 'inserts' => []];
  $src = file_get_contents($seasonsMd);

  // --- landing page -------------------------------------------------------
  if (preg_match('/^### Header — above the list of seasons\s*\n+```html\n(.*?)\n```/ms', $src, $m)) {
    $out['landing']['header'] = trim($m[1]);
  }
  if (preg_match('/^### Footer — below the list of seasons\s*\n+```html\n(.*?)\n```/ms', $src, $m)) {
    $out['landing']['footer'] = trim($m[1]);
  }
  // The landing metatags block is the first "### Metatags" in the file.
  if (preg_match('/^### Metatags\s*\n+\*\*Page title\*\*\s*\n```\n(.*?)\n```\s*\n\*\*Meta description\*\*\s*\n```\n(.*?)\n```/ms', $src, $m)) {
    $out['landing']['title'] = trim($m[1]);
    $out['landing']['description'] = trim($m[2]);
  }

  // --- the five terms -----------------------------------------------------
  // Split on the season headings so each block is self-contained.
  $parts = preg_split('/^## ([A-Za-z\- ]+) — `\/about-us\/seasons\/([a-z\-]+)`\s*$/m', $src, -1, PREG_SPLIT_DELIM_CAPTURE);
  for ($i = 1; $i < count($parts); $i += 3) {
    $name = trim($parts[$i]);
    $slug = trim($parts[$i + 1]);
    $body = $parts[$i + 2];
    $term = ['name' => $name, 'slug' => $slug];
    foreach ([
      'field_short_description' => '/^### `field_short_description`\s*\n+```\n(.*?)\n```/ms',
      'field_public_description' => '/^### `field_public_description`\s*\n+```html\n(.*?)\n```/ms',
      'field_teammate_description' => '/^### `field_teammate_description`\s*\n+```html\n(.*?)\n```/ms',
      'field_call_to_action' => '/^### `field_call_to_action`\s*\n+```html\n(.*?)\n```/ms',
    ] as $field => $re) {
      $term[$field] = preg_match($re, $body, $m) ? trim($m[1]) : NULL;
    }
    if (preg_match('/^### Metatags\s*\n+\*\*Page title\*\*\s*\n```\n(.*?)\n```\s*\n\*\*Meta description\*\*\s*\n```\n(.*?)\n```/ms', $body, $m)) {
      $term['title'] = trim($m[1]);
      $term['description'] = trim($m[2]);
    }
    $out['terms'][$slug] = $term;
  }

  // --- the relocation inserts --------------------------------------------
  $rel = file_get_contents($relocationMd);
  if (preg_match('/^## 1 · WINTER.*?```html\n(.*?)\n```/ms', $rel, $m)) { $out['inserts']['winter_body'] = trim($m[1]); }
  if (preg_match('/^## 2 · SPRING.*?```html\n(.*?)\n```/ms', $rel, $m)) { $out['inserts']['spring_body'] = trim($m[1]); }
  if (preg_match('/### Winter `field_short_description` — revised\s*\n+.*?```\n(.*?)\n```/ms', $rel, $m)) { $out['inserts']['winter_short'] = trim($m[1]); }
  if (preg_match('/### Winter meta description — revised\s*\n+```\n(.*?)\n```/ms', $rel, $m)) { $out['inserts']['winter_meta'] = trim($m[1]); }
  return $out;
}
