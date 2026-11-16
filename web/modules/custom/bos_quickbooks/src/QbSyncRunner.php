<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * The job queue one Web Connector session walks.
 *
 * Three jobs in order — class, customer, item — each a full-list query
 * (active and inactive) paged with QuickBooks iterators where the list
 * supports them. sendRequestXML asks nextRequest() for the next page;
 * receiveResponseXML hands the answer to handleResponse(), which upserts the
 * mirror, flags what went missing once a list is complete, and returns the
 * percent-done the connector expects (100 ends the session).
 *
 * A list whose query fails is marked failed and the run moves on, so one bad
 * list never blocks the others; the run then counts as PARTIAL, not a full
 * sync, and the staleness clock keeps running. A connector-side error
 * (hresult) ends the session with -1: the next scheduled poll starts over.
 */
final class QbSyncRunner {

  public const JOBS = ['class', 'customer', 'item'];
  public const STATE_LAST_FULL_SYNC = 'bos_quickbooks.last_full_sync';
  public const STATE_LAST_RUN = 'bos_quickbooks.last_run';
  public const STATE_LAST_ERROR = 'bos_quickbooks.last_error';
  public const STATE_LAST_LIST_SYNC = 'bos_quickbooks.last_list_sync';

  public function __construct(
    private readonly QbXml $xml,
    private readonly QbListMirror $mirror,
    private readonly QbMonitor $monitor,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Puts the three list jobs on a fresh session.
   */
  public function start(array &$session): void {
    $session['jobs'] = array_map(static fn(string $bundle) => [
      'bundle' => $bundle,
      'status' => 'pending',
      'iterator_id' => NULL,
      'page' => 0,
      'returned' => 0,
      'remaining' => NULL,
      'created' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'missing' => 0,
      'seen' => [],
      'error' => '',
    ], self::JOBS);
    $session['current'] = 0;
    $session['completed'] = FALSE;
  }

  /**
   * The next qbXML request, or '' when every job is done.
   */
  public function nextRequest(array &$session): string {
    $settings = $this->configFactory->get('bos_quickbooks.settings');
    $max = (int) ($settings->get('max_returned') ?: 500);
    $version = (string) ($session['qbxml_version'] ?: ($settings->get('qbxml_version') ?: '16.0'));
    while (isset($session['jobs'][$session['current']])) {
      $job = &$session['jobs'][$session['current']];
      if ($job['status'] === 'done' || $job['status'] === 'failed') {
        $session['current']++;
        continue;
      }
      $job['status'] = 'running';
      $job['page']++;
      $request_id = $session['current'] . '-' . $job['page'];
      $rq = $this->xml->listQuery($job['bundle'], $request_id, $max, $job['iterator_id']);
      $session['requests'] = (int) ($session['requests'] ?? 0) + 1;
      return $this->xml->envelope($rq, $version);
    }
    return '';
  }

  /**
   * Processes one response; returns the connector's percent-done.
   */
  public function handleResponse(array &$session, string $xml, string $hresult, string $message): int {
    $idx = (int) ($session['current'] ?? 0);
    if (!isset($session['jobs'][$idx])) {
      return 100;
    }
    $job = &$session['jobs'][$idx];
    $now = $this->time->getRequestTime();

    if ($hresult !== '' || $xml === '') {
      $error = sprintf('Connector error on %s query (page %d): %s %s', $job['bundle'], $job['page'], $hresult, $message);
      $this->fail($session, $job, $error);
      return -1;
    }
    try {
      $parsed = $this->xml->parse($xml);
    }
    catch (\RuntimeException $e) {
      $this->fail($session, $job, sprintf('Unreadable %s response (page %d): %s', $job['bundle'], $job['page'], $e->getMessage()));
      return -1;
    }
    if ($parsed['bundle'] !== $job['bundle']) {
      $this->logger->warning('Expected a @want response, got @got (requestID @id).', ['@want' => $job['bundle'], '@got' => $parsed['bundle'], '@id' => $parsed['request_id']]);
    }
    // statusCode 1 = "no match" — an empty list is a complete list.
    if ($parsed['status_code'] !== 0 && $parsed['status_code'] !== 1) {
      $this->fail($session, $job, sprintf('QuickBooks refused the %s query: %s (%d %s)', $job['bundle'], $parsed['status_message'], $parsed['status_code'], $parsed['status_severity']));
      $session['current']++;
      return $this->progress($session);
    }

    foreach ($parsed['records'] as $rec) {
      try {
        $result = $this->mirror->upsert($job['bundle'], $rec, $now);
      }
      catch (\Throwable $e) {
        $this->logger->error('Could not store QuickBooks @bundle @id ("@name"): @message', ['@bundle' => $job['bundle'], '@id' => $rec['list_id'] ?? '?', '@name' => $rec['full_name'] ?? '?', '@message' => $e->getMessage()]);
        continue;
      }
      $job[$result]++;
      $job['seen'][] = $rec['list_id'];
    }
    $job['returned'] += count($parsed['records']);
    $job['remaining'] = $parsed['iterator_remaining'];
    $job['iterator_id'] = $parsed['iterator_id'];

    if (QbXml::ITERATES[$job['bundle']] && $parsed['iterator_remaining'] > 0 && $parsed['iterator_id']) {
      return $this->progress($session);
    }

    // The list is complete: anything we did not see is gone from QuickBooks.
    $job['missing'] = $this->mirror->markMissing($job['bundle'], $job['seen']);
    $job['seen'] = [];
    $job['status'] = 'done';
    $last_list = $this->state->get(self::STATE_LAST_LIST_SYNC, []);
    $last_list[$job['bundle']] = $now;
    $this->state->set(self::STATE_LAST_LIST_SYNC, $last_list);
    $this->logger->info('QuickBooks @bundle list synced: @returned returned (@created new, @updated changed, @unchanged unchanged, @missing now missing) in @pages page(s).', [
      '@bundle' => $job['bundle'],
      '@returned' => $job['returned'],
      '@created' => $job['created'],
      '@updated' => $job['updated'],
      '@unchanged' => $job['unchanged'],
      '@missing' => $job['missing'],
      '@pages' => $job['page'],
    ]);
    $session['current']++;
    $progress = $this->progress($session);
    if ($progress >= 100) {
      $this->complete($session);
    }
    return $progress;
  }

  /**
   * Percent-done across the three jobs, 100 only when all are finished.
   */
  public function progress(array $session): int {
    $jobs = $session['jobs'] ?? [];
    if (!$jobs) {
      return 100;
    }
    $done = 0;
    $fraction = 0.0;
    foreach ($jobs as $job) {
      if ($job['status'] === 'done' || $job['status'] === 'failed') {
        $done++;
      }
      elseif ($job['status'] === 'running' && $job['remaining'] !== NULL && ($job['returned'] + $job['remaining']) > 0) {
        $fraction = $job['returned'] / ($job['returned'] + $job['remaining']);
      }
    }
    if ($done >= count($jobs)) {
      return 100;
    }
    return (int) min(99, floor(100 * ($done + $fraction) / count($jobs)));
  }

  /**
   * Every job finished: write the run summary; a clean run is a full sync.
   */
  public function complete(array &$session): void {
    if (!empty($session['completed'])) {
      return;
    }
    $session['completed'] = TRUE;
    $now = $this->time->getRequestTime();
    $failed = array_filter($session['jobs'], static fn($j) => $j['status'] === 'failed');
    $summary = $this->summary($session, $failed ? 'partial' : 'ok', $now);
    $this->state->set(self::STATE_LAST_RUN, $summary);
    if ($failed) {
      $this->logger->error('QuickBooks sync finished PARTIAL — failed list(s): @lists. The mirror stays stale until a clean run.', [
        '@lists' => implode(', ', array_map(static fn($j) => $j['bundle'] . ' (' . $j['error'] . ')', $failed)),
      ]);
      return;
    }
    $this->state->set(self::STATE_LAST_FULL_SYNC, $summary);
    $this->state->delete(self::STATE_LAST_ERROR);
    $this->logger->info('QuickBooks sync complete in @s s: @stats', ['@s' => $summary['duration'], '@stats' => $summary['text']]);
    $this->monitor->recordFullSync($summary);
  }

  /**
   * Called at closeConnection / connectionError: records an unfinished run.
   */
  public function finish(array $session, string $reason): void {
    if (!empty($session['completed']) || empty($session['jobs'])) {
      return;
    }
    $now = $this->time->getRequestTime();
    $summary = $this->summary($session, 'aborted', $now);
    $summary['reason'] = $reason;
    $this->state->set(self::STATE_LAST_RUN, $summary);
    $this->logger->warning('QuickBooks sync ended early (@reason) after @n request(s): @stats', ['@reason' => $reason, '@n' => $session['requests'] ?? 0, '@stats' => $summary['text']]);
  }

  private function fail(array &$session, array &$job, string $error): void {
    $job['status'] = 'failed';
    $job['error'] = $error;
    $job['seen'] = [];
    $session['last_error'] = $error;
    $this->state->set(self::STATE_LAST_ERROR, ['at' => $this->time->getRequestTime(), 'message' => $error]);
    $this->logger->error('@error', ['@error' => $error]);
  }

  private function summary(array $session, string $status, int $now): array {
    $parts = [];
    $lists = [];
    foreach ($session['jobs'] as $job) {
      $lists[$job['bundle']] = [
        'status' => $job['status'],
        'returned' => $job['returned'],
        'created' => $job['created'],
        'updated' => $job['updated'],
        'unchanged' => $job['unchanged'],
        'missing' => $job['missing'],
        'pages' => $job['page'],
        'error' => $job['error'],
      ];
      $parts[] = sprintf('%s %s (%d returned, %d new, %d changed, %d missing)', $job['bundle'], $job['status'], $job['returned'], $job['created'], $job['updated'], $job['missing']);
    }
    return [
      'at' => $now,
      'started' => (int) ($session['started'] ?? $now),
      'duration' => $now - (int) ($session['started'] ?? $now),
      'status' => $status,
      'requests' => (int) ($session['requests'] ?? 0),
      'company_file' => (string) ($session['company_file'] ?? ''),
      'company_name' => (string) ($session['company_name'] ?? ''),
      'qbxml_version' => (string) ($session['qbxml_version'] ?? ''),
      'lists' => $lists,
      'text' => implode('; ', $parts),
    ];
  }

}
