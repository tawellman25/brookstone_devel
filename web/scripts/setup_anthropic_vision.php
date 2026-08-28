<?php

/**
 * Configure Claude (Anthropic) as the vision provider for invoice extraction.
 * Idempotent, entity/config-API — run per environment (no cim).
 *
 * - Creates the `anthropic_api_key` Key entity (env provider, reads
 *   ANTHROPIC_API_KEY — the value lives in the environment, never in git),
 *   mirroring how `openai_api_key` is set up.
 * - Points ai_provider_anthropic at that key.
 * - Sets the `chat_with_image_vision` operation to anthropic / claude-sonnet-4-5.
 * - Adds key.key.anthropic_api_key to config_ignore so it is never committed.
 *
 * The API key VALUE is added separately to the environment (settings.php
 * putenv on live; DDEV env on dev) — this script does not handle the secret.
 */

use Drupal\key\Entity\Key;

$MODEL = 'claude-sonnet-4-5';
$ENV_VAR = 'ANTHROPIC_API_KEY';

// 1. Key entity (env provider) — mirror the openai key.
$storage = \Drupal::entityTypeManager()->getStorage('key');
if (!$storage->load('anthropic_api_key')) {
  Key::create([
    'id' => 'anthropic_api_key',
    'label' => 'Anthropic API Key',
    'description' => 'Claude API key for AI vision (invoice extraction). Value from env ' . $ENV_VAR . '.',
    'key_type' => 'authentication',
    'key_type_settings' => [],
    'key_provider' => 'env',
    'key_provider_settings' => ['env_variable' => $ENV_VAR, 'base64_encoded' => FALSE],
    'key_input' => 'none',
    'key_input_settings' => [],
  ])->save();
  echo "Created key entity anthropic_api_key (env: $ENV_VAR)\n";
}
else {
  echo "key entity anthropic_api_key already exists\n";
}

// 2. Point the anthropic provider at that key.
$prov = \Drupal::configFactory()->getEditable('ai_provider_anthropic.settings');
if ($prov->get('api_key') !== 'anthropic_api_key') {
  $prov->set('api_key', 'anthropic_api_key')->save();
  echo "Set ai_provider_anthropic.api_key = anthropic_api_key\n";
}
else {
  echo "ai_provider_anthropic.api_key already set\n";
}

// 3. Route the vision operation to Claude.
$ai = \Drupal::configFactory()->getEditable('ai.settings');
$ai->set('default_providers.chat_with_image_vision', [
  'provider_id' => 'anthropic',
  'model_id' => $MODEL,
])->save();
echo "Set chat_with_image_vision -> anthropic / $MODEL\n";

// 4. Keep the key entity out of git.
$ci = \Drupal::configFactory()->getEditable('config_ignore.settings');
$list = $ci->get('ignored_config_entities') ?? [];
if (!in_array('key.key.anthropic_api_key', $list, TRUE)) {
  $list[] = 'key.key.anthropic_api_key';
  $ci->set('ignored_config_entities', $list)->save();
  echo "Added key.key.anthropic_api_key to config_ignore\n";
}
else {
  echo "config_ignore already covers the anthropic key\n";
}

// Verify what the extractor will see.
$def = \Drupal::service('ai.provider')->getDefaultProviderForOperationType('chat_with_image_vision');
echo "\nVision operation now resolves to: " . json_encode($def) . "\n";
$k = $storage->load('anthropic_api_key');
$v = $k ? (string) $k->getKeyValue() : '';
echo "ANTHROPIC_API_KEY value present: " . (strlen($v) > 10 ? "YES ({$ENV_VAR} set)" : "NO — add it to the environment") . "\n";
