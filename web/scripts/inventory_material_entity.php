<?php

declare(strict_types=1);

/**
 * Read-only inventory of the `material` ECK entity.
 * Prints a markdown report:
 *   1. Bundles (machine name + label)
 *   2. Fields per bundle (shared vs unique flagged)
 *   3. Entry count per bundle
 *   4. Taxonomy reference fields
 *   5. Lookup of specific bulk/loose materials
 */

$etm = \Drupal::entityTypeManager();
$bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('material');
$db = \Drupal::database();

// ── 1. Bundles ─────────────────────────────────────────────────────────
$bundles = array_keys($bundle_info);
sort($bundles);

// ── 2. Field instances per bundle ──────────────────────────────────────
$instances_by_bundle = [];
$bundle_count_per_field = [];
foreach (\Drupal\field\Entity\FieldConfig::loadMultiple() as $fc) {
  if ($fc->getTargetEntityTypeId() !== 'material') {
    continue;
  }
  $b = $fc->getTargetBundle();
  $fn = $fc->getName();
  $instances_by_bundle[$b][$fn] = $fc;
  $bundle_count_per_field[$fn] = ($bundle_count_per_field[$fn] ?? 0) + 1;
}
$total_bundles = count($bundles);

// ── 3. Counts per bundle ───────────────────────────────────────────────
$counts = [];
foreach ($bundles as $b) {
  $counts[$b] = (int) $db->select('material', 'm')
    ->condition('type', $b)
    ->countQuery()->execute()->fetchField();
}

// ── 4. Taxonomy reference fields (across all bundles) ──────────────────
$tax_refs = [];
foreach (\Drupal\field\Entity\FieldConfig::loadMultiple() as $fc) {
  if ($fc->getTargetEntityTypeId() !== 'material') {
    continue;
  }
  if ($fc->getType() !== 'entity_reference') {
    continue;
  }
  $hs = $fc->getSetting('handler_settings');
  $storage = $fc->getFieldStorageDefinition();
  $target = $storage->getSetting('target_type');
  if ($target !== 'taxonomy_term') {
    continue;
  }
  $tax_refs[$fc->getName()][] = [
    'bundle'        => $fc->getTargetBundle(),
    'label'         => $fc->getLabel(),
    'target_bundles' => array_keys($hs['target_bundles'] ?? []),
  ];
}

// ── 5. Bulk material lookup ────────────────────────────────────────────
$lookups = ['topsoil', 'fill dirt', 'mulch', 'gravel', 'sand', 'compost', 'decomposed granite'];
$lookup_hits = [];
foreach ($lookups as $term) {
  $ids = \Drupal::entityQuery('material')
    ->accessCheck(FALSE)
    ->condition('title', "%$term%", 'LIKE')
    ->range(0, 50)
    ->execute();
  if ($ids) {
    foreach ($etm->getStorage('material')->loadMultiple($ids) as $m) {
      $lookup_hits[$term][] = [
        'id'     => $m->id(),
        'name'   => $m->label(),
        'bundle' => $m->bundle(),
      ];
    }
  }
}

// ──────────────────────────────────────────────────────────────────────
// PRINT MARKDOWN REPORT
// ──────────────────────────────────────────────────────────────────────

echo "# `material` ECK entity — inventory report\n\n";
echo "Generated: " . date('Y-m-d H:i') . "  \n";
echo "Total bundles: " . $total_bundles . "  \n";
echo "Total entries: " . array_sum($counts) . "\n\n";

// 1. Bundle list with labels + counts
echo "## 1. Bundles\n\n";
echo "| Bundle (machine name) | Label | Entries |\n";
echo "|---|---|---:|\n";
foreach ($bundles as $b) {
  $label = $bundle_info[$b]['label'] ?? '(no label)';
  printf("| `%s` | %s | %d |\n", $b, $label, $counts[$b]);
}
echo "\n";

// 2. Fields per bundle
echo "## 2. Fields per bundle\n\n";
echo "Fields marked **(shared)** appear on 2+ bundles; **(unique)** are on this bundle only. `(N/$total_bundles)` shows how many bundles carry the field.\n\n";

foreach ($bundles as $b) {
  $label = $bundle_info[$b]['label'] ?? '(no label)';
  $instances = $instances_by_bundle[$b] ?? [];
  ksort($instances);
  echo "### `$b` — $label  *(" . count($instances) . " bundle fields)*\n\n";
  if (!$instances) {
    echo "_(no bundle-specific field instances)_\n\n";
    continue;
  }
  echo "| Field | Type | Label | Coverage |\n";
  echo "|---|---|---|---|\n";
  foreach ($instances as $fn => $fc) {
    $bc = $bundle_count_per_field[$fn] ?? 1;
    $tag = ($bc >= 2) ? "**shared** ($bc/$total_bundles)" : "**unique** (1/$total_bundles)";
    printf("| `%s` | %s | %s | %s |\n", $fn, $fc->getType(), $fc->getLabel(), $tag);
  }
  echo "\n";
}

// Field-share summary (which fields are most shared)
echo "### Field-share summary (fields ordered by bundle coverage)\n\n";
arsort($bundle_count_per_field);
echo "| Field | On how many bundles |\n";
echo "|---|---:|\n";
foreach ($bundle_count_per_field as $fn => $c) {
  printf("| `%s` | %d / %d |\n", $fn, $c, $total_bundles);
}
echo "\n";

// 3. (Counts already shown above in section 1)

// 4. Taxonomy reference fields
echo "## 3. Taxonomy reference fields on `material`\n\n";
if (!$tax_refs) {
  echo "_(none — no `material` bundle has an entity_reference field targeting `taxonomy_term`)_\n\n";
}
else {
  echo "| Field | Label | Bundle(s) | Target vocabulary(ies) |\n";
  echo "|---|---|---|---|\n";
  foreach ($tax_refs as $fn => $rows) {
    foreach ($rows as $r) {
      $tv = $r['target_bundles'] ? implode(', ', $r['target_bundles']) : '_any vocabulary_';
      printf("| `%s` | %s | `%s` | %s |\n", $fn, $r['label'], $r['bundle'], $tv);
    }
  }
  echo "\n";
}

// Explicitly check for the names the user asked about:
echo "**Specifically: presence of `field_material_type`, `field_category`, or similar?**  \n";
$asked = ['field_material_type', 'field_category', 'field_type', 'field_classification'];
$found_named = [];
foreach (\Drupal\field\Entity\FieldConfig::loadMultiple() as $fc) {
  if ($fc->getTargetEntityTypeId() !== 'material') continue;
  if (in_array($fc->getName(), $asked, TRUE)) {
    $found_named[$fc->getName()][] = $fc->getTargetBundle();
  }
}
if ($found_named) {
  foreach ($found_named as $fn => $bs) {
    echo "- `$fn` — on: " . implode(', ', $bs) . "\n";
  }
}
else {
  echo "- None of `field_material_type`, `field_category`, `field_type`, or `field_classification` exist on any `material` bundle.\n";
}
echo "\n";

// 5. Bulk material lookup
echo "## 4. Bulk / loose material lookup\n\n";
echo "Searching `material.title` LIKE %term% for each:\n\n";
foreach ($lookups as $term) {
  echo "### \"$term\"\n\n";
  if (empty($lookup_hits[$term])) {
    echo "_No matches found._\n\n";
    continue;
  }
  echo "| ID | Name | Bundle |\n";
  echo "|---:|---|---|\n";
  foreach ($lookup_hits[$term] as $h) {
    printf("| %d | %s | `%s` |\n", $h['id'], $h['name'], $h['bundle']);
  }
  echo "\n";
}
