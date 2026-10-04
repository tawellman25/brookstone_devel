<?php
declare(strict_types=1);
/**
 * Replace the hardcoded holiday price in the service page body with the
 * [holiday-price] token that bos_services_preprocess_field() substitutes.
 *
 *   BOS_TOK_APPLY=1 drush php:script web/scripts/tokenize_holiday_price.php
 */
use Drupal\Core\Cache\Cache;
$apply = getenv('BOS_TOK_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
print $apply ? "MODE: APPLY\n" : "MODE: DRY-RUN (BOS_TOK_APPLY=1 to write)\n";

$inner = \Drupal::service('path_alias.manager')->getPathByAlias('/services/christmas-decorations');
if ($inner === '/services/christmas-decorations') { print "ABORT — page does not resolve.\n"; return; }
$t = $etm->getStorage('taxonomy_term')->load((int) str_replace('/taxonomy/term/', '', $inner));
$item = $t->get('field_service_public_desc')->first();
$body = (string) ($item->value ?? '');

$old = 'The program is billed at $8 per foot.';
$new = 'The program is billed at [holiday-price].';
if (str_contains($body, $new)) { print "✓ already tokenized\n"; return; }
if (!str_contains($body, $old)) { print "⚠ the hardcoded sentence is not present — wording changed; NOT guessing.\n"; return; }

printf("  \"%s\"\n  -> \"%s\"\n", $old, $new);
if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }
$t->set('field_service_public_desc', ['value' => str_replace($old, $new, $body),
  'summary' => $item->summary, 'format' => 'full_html'])->save();
Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
drupal_flush_all_caches();
print "\nTokenized. The rate now comes from Business Settings.\n";
