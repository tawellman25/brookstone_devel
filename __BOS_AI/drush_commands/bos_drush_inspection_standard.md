# BOS Drush Inspection Standard (Authoritative)

Use these templates to retrieve BOS configuration and entity details.
This is the single source of truth for BOS inspection and AI-assisted architecture analysis.

---

## Core Rules

- Use **Drupal Entity API via `drush php:eval` only**.
- Do **NOT** use SQL, table inspection, or `drush sql:connect`.
- Always retrieve:
  1. Bundles (if applicable)
  2. Fields (machine | type | label)
  3. Entity references (target entity types)
  4. Taxonomy terms (if vocabulary)
- Always verify module status before assuming functionality exists.

---

## A) ECK / Content Entity — Bundles (machine | label)

Replace `ENTITY_TYPE_ID`.

```bash
drush php:eval '
$bundles = \Drupal::service("entity_type.bundle.info")->getBundleInfo("ENTITY_TYPE_ID");
foreach ($bundles as $machine => $info) {
  echo $machine . " | " . $info["label"] . PHP_EOL;
}
'
```

---

## B) Entity — Fields for One Bundle (machine | type | label)

Replace `ENTITY_TYPE_ID` and `BUNDLE`.

```bash
drush php:eval '
$fields = \Drupal::service("entity_field.manager")->getFieldDefinitions("ENTITY_TYPE_ID", "BUNDLE");
foreach ($fields as $name => $def) {
  echo $name . " | " . $def->getType() . " | " . $def->getLabel() . PHP_EOL;
}
'
```

---

## C) Entity — Fields for All Bundles

Replace `ENTITY_TYPE_ID`.

```bash
drush php:eval '
$entity_type = "ENTITY_TYPE_ID";
$bundles = \Drupal::service("entity_type.bundle.info")->getBundleInfo($entity_type);

foreach ($bundles as $bundle => $info) {
  echo PHP_EOL . "=== " . $bundle . " | " . $info["label"] . " ===" . PHP_EOL;
  $fields = \Drupal::service("entity_field.manager")->getFieldDefinitions($entity_type, $bundle);
  foreach ($fields as $name => $def) {
    echo $name . " | " . $def->getType() . " | " . $def->getLabel() . PHP_EOL;
  }
}
'
```

---

## D) Entity — Entity Reference Targets Only

Replace `ENTITY_TYPE_ID` and `BUNDLE`.

```bash
drush php:eval '
$fields = \Drupal::service("entity_field.manager")->getFieldDefinitions("ENTITY_TYPE_ID", "BUNDLE");
foreach ($fields as $name => $def) {
  if ($def->getType() === "entity_reference") {
    $target = $def->getSetting("target_type") ?: "-";
    echo $name . " → " . $target . PHP_EOL;
  }
}
'
```

---

## E) Taxonomy — List Vocabularies (machine | label)

```bash
drush php:eval '
foreach (\Drupal::entityTypeManager()->getStorage("taxonomy_vocabulary")->loadMultiple() as $vocab) {
  echo $vocab->id() . " | " . $vocab->label() . PHP_EOL;
}
'
```

---

## F) Taxonomy — List Terms (tid | label | weight)

Replace `VOCABULARY_ID`.

```bash
drush php:eval '
$terms = \Drupal::entityTypeManager()->getStorage("taxonomy_term")->loadTree("VOCABULARY_ID", 0, NULL, TRUE);
foreach ($terms as $term) {
  echo $term->id() . " | " . $term->label() . " | weight=" . $term->getWeight() . PHP_EOL;
}
'
```

---

## G) Taxonomy — Dump Term Fields

Replace `VOCABULARY_ID`.

```bash
drush php:eval '
$fields = \Drupal::service("entity_field.manager")->getFieldDefinitions("taxonomy_term", "VOCABULARY_ID");
foreach ($fields as $name => $def) {
  echo $name . " | " . $def->getType() . " | " . $def->getLabel() . PHP_EOL;
}
'
```

---

## H) Module Inspection (Required for BOS)

### List Enabled Non-Core Modules

```bash
drush pml --type=module --status=enabled --no-core --format=table
```

### Check Specific Module

Replace `MODULE_MACHINE_NAME`.

```bash
drush pml MODULE_MACHINE_NAME
```

Example:

```bash
drush pml estimate
```

### Confirm via core.extension (Authoritative)

```bash
drush config:get core.extension module
```

### Confirm Entity Type Exists

Replace `ENTITY_TYPE_ID`.

```bash
drush php:eval '
$def = \Drupal::entityTypeManager()->getDefinition("ENTITY_TYPE_ID", FALSE);
echo $def ? "EXISTS" : "NOT FOUND";
'
```

### Install Module + Clear Cache

Replace `MODULE_MACHINE_NAME`.

```bash
drush en MODULE_MACHINE_NAME -y
drush cr
```

---

## BOS Standard Output Format

When pasting into AI discussions:

```
ENTITY: ENTITY_TYPE_ID
BUNDLE: bundle_name (if applicable)

OUTPUT:
[paste raw output here]
```

Do not edit or summarize output.

---

## BOS Non-Negotiable Rule

All BOS inspection and documentation must use this standard.
SQL inspection is not permitted.

