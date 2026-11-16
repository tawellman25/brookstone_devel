<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks\Commands;

use Drupal\bos_quickbooks\CustomerMatcher;
use Drupal\bos_quickbooks\QbMonitor;
use Drupal\bos_quickbooks\QbwcSession;
use Drush\Commands\DrushCommands;

/**
 * Drush: status, the matching pass, the connector password.
 */
final class QuickBooksCommands extends DrushCommands {

  public function __construct(
    private readonly QbwcSession $session,
    private readonly QbMonitor $monitor,
    private readonly CustomerMatcher $matcher,
  ) {
    parent::__construct();
  }

  /**
   * QuickBooks sync status (what the status page shows, in text).
   *
   * @command bos:qb:status
   * @aliases qb-status
   */
  public function status(): void {
    $f = $this->monitor->inspect();
    $date = static fn(?int $ts) => $ts ? date('m/d/Y g:i A T', $ts) : 'never';
    $this->io()->writeln('Mirror:            ' . ($f['never'] ? 'NEVER SYNCED' : ($f['stale'] ? 'STALE' : 'current')) . " (window {$f['stale_hours']} h)");
    $this->io()->writeln('Last complete sync: ' . ($f['last_full_sync'] ? $date((int) $f['last_full_sync']['at']) . ' — ' . $f['last_full_sync']['text'] : 'never'));
    $this->io()->writeln('Last contact:      ' . $date($f['last_contact']));
    $this->io()->writeln('Company:           ' . ($f['company'] ? ($f['company']['name'] ?: '?') . ' — ' . ($f['company']['file'] ?: '?') . ' (' . ($f['company']['product'] ?: 'QuickBooks') . ')' : 'not reported'));
    $this->io()->writeln('Connector version: ' . ($f['connector_version'] ?: 'unknown'));
    $this->io()->writeln('Password set:      ' . ($f['password_set'] ? 'yes' : 'NO'));
    $this->io()->writeln('Session:           ' . ($f['session'] ? 'in progress, ' . (int) ($f['session']['requests'] ?? 0) . ' request(s)' : 'none'));
    $this->io()->writeln('Last run:          ' . ($f['last_run'] ? strtoupper($f['last_run']['status']) . ' ' . $date((int) $f['last_run']['at']) . ' — ' . $f['last_run']['text'] : 'none'));
    $this->io()->writeln('Last error:        ' . ($f['last_error'] ? $date((int) $f['last_error']['at']) . ' — ' . $f['last_error']['message'] : 'none'));
    foreach ($f['counts'] as $bundle => $c) {
      $this->io()->writeln(sprintf('%-10s total %d · active %d · missing %d', $bundle, $c['total'], $c['active'], $c['missing']));
    }
    $this->io()->writeln('Matching:          ' . implode(' · ', array_map(static fn($k, $v) => "$k $v", array_keys($f['match_counts']), $f['match_counts'])));
  }

  /**
   * Run the customer name → ListID matching pass.
   *
   * @command bos:qb:match
   * @aliases qb-match
   * @option dry-run Report only, write nothing.
   * @option rematch Re-evaluate rows the pass matched earlier (never Manual / Ignored).
   */
  public function match(array $options = ['dry-run' => FALSE, 'rematch' => FALSE]): void {
    $stats = $this->matcher->run((bool) $options['dry-run'], (bool) $options['rematch']);
    $this->io()->writeln(sprintf('%s%d scanned — %d auto, %d ambiguous, %d unmatched, %d duplicate, %d unchanged',
      $stats['dry_run'] ? '[DRY RUN] ' : '', $stats['scanned'], $stats['auto'], $stats['ambiguous'], $stats['unmatched'], $stats['duplicate'], $stats['unchanged']));
    foreach (['ambiguous', 'unmatched', 'duplicate'] as $k) {
      if (!empty($stats['samples'][$k])) {
        $this->io()->writeln("  $k e.g.: " . implode(' | ', $stats['samples'][$k]));
      }
    }
  }

  /**
   * Set the Web Connector password (stored as a hash in State).
   *
   * @command bos:qb:set-password
   * @param string $password The new password.
   */
  public function setPassword(string $password): void {
    if (strlen($password) < 12) {
      throw new \InvalidArgumentException('Use at least 12 characters.');
    }
    $this->session->setPassword($password);
    $this->io()->success('Password set for user "' . $this->session->username() . '".');
  }

}
