<?php
declare(strict_types=1);
/**
 * Funnel the Holiday Decorations service page into /holiday-lights.
 *
 * Two pages, two jobs: the service page ranks, the landing page converts. No
 * redirect — the service page keeps its path, its menu placement and its
 * evergreen content, and gains a CTA at the top.
 *
 *   BOS_CTA2_APPLY=1 drush php:script web/scripts/add_holiday_cta_to_service_page.php
 */
use Drupal\Core\Cache\Cache;
$apply = getenv('BOS_CTA2_APPLY') === '1';
print $apply ? "MODE: APPLY\n" : "MODE: DRY-RUN (BOS_CTA2_APPLY=1 to write)\n";

if (!\Drupal::service('path.validator')->isValid('/holiday-lights')) {
  print "ABORT — /holiday-lights does not resolve; not linking at a dead page.\n"; return;
}
print "✓ /holiday-lights resolves\n";

$inner = \Drupal::service('path_alias.manager')->getPathByAlias('/services/christmas-decorations');
$t = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load((int) str_replace('/taxonomy/term/', '', $inner));
$item = $t->get('field_service_public_desc')->first();
$body = (string) ($item->value ?? '');

$cta = '<p class="bo-service-cta"><a class="button" href="/holiday-lights">See 2026 pricing and get a quote</a></p>';
if (str_contains($body, '/holiday-lights')) { print "✓ already links to the landing page\n"; return; }

printf("+ prepending CTA (body %d → %d chars)\n", mb_strlen($body), mb_strlen($cta . "\n\n" . $body));
if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }
$t->set('field_service_public_desc', ['value' => $cta . "\n\n" . $body, 'summary' => $item->summary, 'format' => 'full_html'])->save();
Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
drupal_flush_all_caches();
print "\nDone — service page now funnels into the landing page.\n";
