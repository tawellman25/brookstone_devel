<?php

declare(strict_types=1);

namespace Drupal\bos_content_coverage\Commands;

use Drupal\bos_content_coverage\ContentCoverage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Internal-link check across public term copy.
 *
 * The cross-link failure this is for: copy was written against the structure we
 * designed, so it referenced /material/plants/trees/deciduous/fruit-trees while
 * the live alias is /deciduous/fruit. Those links would have 404'd, and the only
 * reason they did not is that somebody checked by hand afterwards.
 *
 * Reports as a command rather than a page because the answer is a list of links,
 * not a list of entities, and the coverage report's alias column is what lets
 * copy be written against real paths in the first place.
 */
final class LinkCheckCommands extends DrushCommands {

  public function __construct(
    private readonly ContentCoverage $coverage,
    private readonly EntityTypeManagerInterface $etm,
    private readonly AliasManagerInterface $aliasManager,
  ) {
    parent::__construct();
  }

  /**
   * Find internal links in public term copy that do not resolve.
   *
   * @command bos:content:linkcheck
   * @aliases bos-linkcheck
   * @option all Also list the links that resolve.
   */
  public function linkCheck(array $options = ['all' => FALSE]): int {
    $roles = array_keys(ContentCoverage::ROLES);
    $terms = $this->etm->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $this->coverage->coveredVids()]);

    $checked = 0;
    $broken = [];
    $redirects = [];
    $okCount = 0;

    foreach ($terms as $term) {
      foreach ($roles as $role) {
        $field = $this->coverage->fieldFor($term->bundle(), $role);
        if (!$field || !$term->hasField($field) || $term->get($field)->isEmpty()) {
          continue;
        }
        $html = (string) ($term->get($field)->first()->getValue()['value'] ?? '');
        // Internal links only — an external href is not ours to validate, and
        // mailto/tel are not paths.
        //
        // Delimiter is ~, NOT #. The character class has to exclude #, and an
        // unescaped # inside a #-delimited pattern ends the pattern early, so it
        // never compiles and preg_match_all returns FALSE. That is exactly how an
        // earlier link audit reported "every link resolves" having checked zero
        // of them, which is why the hard failure below exists.
        $found = preg_match_all('~href\s*=\s*["\'](/[^"\'#]*)["\']~i', $html, $m);
        if ($found === FALSE) {
          throw new \RuntimeException('The link pattern failed to compile — refusing to report a result.');
        }
        foreach (array_unique($m[1] ?? []) as $href) {
          $checked++;
          $path = strtok($href, '?');
          if ($path === '' || $path === FALSE) {
            continue;
          }
          $status = $this->resolveStatus($path);
          if ($status === 'alias' || $status === 'route') {
            $okCount++;
            if ($options['all']) {
              $this->io()->writeln(sprintf('  ok      %-52s %s / %s', $path, $term->label(), $role));
            }
            continue;
          }
          if ($status === 'redirect') {
            // Works for a visitor, but goes through a 301. Not broken — worth
            // repointing so copy links straight at the canonical path.
            $redirects[] = [
              'link' => $path,
              'term' => (string) $term->label(),
              'vocabulary' => $term->bundle(),
              'field' => $field,
              'page' => $this->coverage->alias($term),
            ];
            continue;
          }
          $broken[] = [
            'link' => $path,
            'term' => (string) $term->label(),
            'vocabulary' => $term->bundle(),
            'field' => $field,
            'page' => $this->coverage->alias($term),
          ];
        }
      }
    }

    $this->io()->writeln('');
    $this->io()->writeln(sprintf('%d internal link(s) checked across %d public terms: %d resolve directly, %d via a redirect, %d DO NOT resolve.',
      $checked, count($terms), $okCount, count($redirects), count($broken)));

    // A clean result on zero links is not a clean result; it means the check
    // did not run. Say so rather than implying the copy is link-clean.
    if ($checked === 0) {
      $this->io()->warning('No internal links were found at all. That is suspicious — verify the extraction before trusting this.');
      return self::EXIT_FAILURE;
    }

    foreach ($broken as $b) {
      $this->io()->writeln('');
      $this->io()->writeln(sprintf('  BROKEN    %s', $b['link']));
      $this->io()->writeln(sprintf('            in %s (%s), %s', $b['term'], $b['vocabulary'], $b['field']));
      $this->io()->writeln(sprintf('            on %s', $b['page']));
    }
    foreach ($redirects as $r) {
      $this->io()->writeln('');
      $this->io()->writeln(sprintf('  REDIRECT  %s', $r['link']));
      $this->io()->writeln(sprintf('            in %s (%s), %s', $r['term'], $r['vocabulary'], $r['field']));
      $this->io()->writeln(sprintf('            on %s', $r['page']));
    }
    return $broken ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * How does this internal path resolve: 'alias', 'route', 'redirect' or 'none'?
   *
   * The redirect case has to be separated out. /lighting and
   * /lighting/landscape-lighting both answer 301 and work perfectly for a
   * visitor; checking only the alias table and the router called them broken,
   * which would have sent marketing to fix two links that are not broken. They
   * are worth repointing at the canonical path, which is a different and much
   * weaker instruction than "this 404s".
   */
  private function resolveStatus(string $path): string {
    // An alias resolving to something other than itself is a real page.
    if ($this->aliasManager->getPathByAlias($path) !== $path) {
      return 'alias';
    }
    // A plain route, like /request-estimate.
    try {
      \Drupal::service('router')->match($path);
      return 'route';
    }
    catch (ResourceNotFoundException | \Throwable $e) {
      // Fall through.
    }
    // The redirect module stores sources without a leading slash.
    if (\Drupal::moduleHandler()->moduleExists('redirect')) {
      $source = ltrim($path, '/');
      $found = \Drupal::database()->select('redirect', 'r')
        ->fields('r', ['rid'])
        ->condition('redirect_source__path', $source)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($found) {
        return 'redirect';
      }
    }
    return 'none';
  }

}
