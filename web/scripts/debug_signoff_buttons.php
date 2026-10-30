<?php

/**
 * @file
 * List every button on the sign-off form with its submit NAME, and test which
 * one Drupal resolves as the triggering element when "Refresh man hours" is
 * the submitted value.
 *
 * The refresh button renders as name="op" — the same submit name the Save
 * button uses — so Drupal has to tell them apart by VALUE. If that resolution
 * lands on the wrong button, the click behaves like a Save, which would match
 * the reported "it only works after you try to save".
 *
 * Read-only. Run: drush php:script web/scripts/debug_signoff_buttons.php
 */

use Drupal\Core\Form\FormState;

$etm = \Drupal::entityTypeManager();
$ids = $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'sprinkler_winterizing')->sort('id', 'DESC')->range(0, 1)->execute();
$woId = (int) reset($ids);
$entity = $etm->getStorage('wo_complete_info')->create([
  'type' => 'irrigation_crew',
  'field_work_order' => $woId,
]);
$formObject = $etm->getFormObject('wo_complete_info', 'default');
$formObject->setEntity($entity);

$formState = new FormState();
$formState->setFormObject($formObject);
$form = \Drupal::formBuilder()->buildForm($formObject, $formState);

$buttons = [];
$walk = function (array $el, string $path) use (&$walk, &$buttons) {
  foreach ($el as $key => $child) {
    if (!is_array($child) || (is_string($key) && str_starts_with($key, '#'))) {
      continue;
    }
    $type = $child['#type'] ?? '';
    if ($type === 'submit' || $type === 'button') {
      $buttons[] = [
        'path' => $path . '/' . $key,
        'type' => $type,
        'name' => $child['#name'] ?? '(none → "op")',
        'value' => (string) ($child['#value'] ?? ''),
        'lve' => array_key_exists('#limit_validation_errors', $child)
          ? json_encode($child['#limit_validation_errors']) : 'unset',
        'ajax' => isset($child['#ajax']) ? 'yes' : 'no',
      ];
    }
    $walk($child, $path . '/' . $key);
  }
};
$walk($form, '');

printf("Buttons on the sign-off form (%d):\n", count($buttons));
foreach ($buttons as $b) {
  printf("  %-44s type=%-6s name=%-18s ajax=%-3s lve=%-6s value=%s\n",
    $b['path'], $b['type'], $b['name'], $b['ajax'], $b['lve'], $b['value']);
}

// How many share the default "op" name?
$op = array_filter($buttons, fn($b) => $b['name'] === '(none → "op")');
printf("\nButtons sharing name=\"op\": %d%s\n", count($op),
  count($op) > 1 ? '  ← Drupal must disambiguate these by VALUE' : '');
foreach ($op as $b) {
  printf("   value=\"%s\"\n", $b['value']);
}
