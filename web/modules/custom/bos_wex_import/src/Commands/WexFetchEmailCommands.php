<?php

declare(strict_types=1);

namespace Drupal\bos_wex_import\Commands;

use Drupal\bos_wex_import\Service\WexFuelImportService;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Drush command — fetch WEX exports from the IMAP mailbox.
 *
 * Reads UNSEEN messages from a WEX-monitored inbox, extracts the
 * report-download URL from each body, fetches the CSV over HTTP, and
 * hands the file to the existing channel-agnostic import core
 * (WexFuelImportService::importFromFile). No parse/import logic lives
 * here — that's reused, not reimplemented.
 *
 * Why webklex/php-imap (not ext-imap):
 *   The PHP C extension is deprecated and slated for removal. The
 *   pure-PHP webklex package speaks IMAP4 directly so we don't depend
 *   on a sunsetting native extension on production hosts.
 *
 * Config (read from $settings via Settings::get — NEVER hardcoded):
 *
 *   $settings['wex_imap']['host']            (required)
 *   $settings['wex_imap']['port']            (default 993)
 *   $settings['wex_imap']['username']        (required)
 *   $settings['wex_imap']['password']        (required — read from
 *                                             env in the local file)
 *   $settings['wex_imap']['encryption']      (default 'ssl')
 *   $settings['wex_imap']['validate_cert']   (default TRUE)
 *   $settings['wex_imap']['sender_match']    (default 'wexonline.com',
 *                                             a case-insensitive
 *                                             substring match against
 *                                             the From address)
 *
 * Behavior summary:
 *   1. Connect; bail on connection failure (failure exit).
 *   2. Open INBOX; find UNSEEN messages with leaveUnread() so the
 *      query itself does not mark them read — we control read state
 *      explicitly per-message based on outcome.
 *   3. Filter by sender (substring of the From mailbox / full).
 *   4. For each match, oldest first:
 *      a. Extract WEX download URL from the body.
 *      b. Fetch with Guzzle (60s timeout, follow redirects).
 *      c. Hand temp file to importFromFile().
 *      d. Delete temp file.
 *      e. Mark SEEN only on a clean run (no file-level failure AND
 *         a URL was found). Otherwise leave UNSEEN so the next run
 *         picks it up — silent loss is the worst outcome here.
 *   5. Print and log per-message summaries + a grand total.
 *
 * Exit-code policy: failure exit ONLY on missing config or
 * connection failure. Empty mailbox, no-URL messages, file-level
 * errors per message, and row-level errors are normal operational
 * outcomes — surfaced in the summary, not raised as failures.
 *
 * Idempotency: the underlying import dedupes on Transaction ID, so
 * an accidental re-fetch of an overlapping window causes no double
 * entry. Marking SEEN prevents re-processing the same email; even
 * if a flag set fails silently, the dedupe guard still holds.
 */
final class WexFetchEmailCommands extends DrushCommands {

  /**
   * Regex for the WEX report download URL inside the email body.
   *
   * Anchored to the known wexonline.com goto-download endpoint.
   * Stops at whitespace and at common HTML attribute / tag boundary
   * characters so we don't accidentally swallow markup.
   */
  private const WEX_DOWNLOAD_URL_REGEX =
    '#https?://go\.wexonline\.com/web/gotoDownloadReport\.do\?[^\s"\'<>)]+#i';

  public function __construct(
    private readonly WexFuelImportService $importService,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  /**
   * Fetch unread WEX emails and import each attached CSV.
   *
   * @command bos_wex_import:fetch-email
   * @aliases wex:fetch-email
   * @usage drush bos_wex_import:fetch-email
   *   Reads UNSEEN messages from the configured WEX mailbox, downloads
   *   each linked report, and imports it via the shared import core.
   * @usage drush wex:fetch-email
   *   Same, via short alias.
   */
  public function fetch(): int {
    $logger = $this->loggerFactory->get('bos_wex_import');

    // 1. Resolve config.
    $config = $this->loadConfig();
    if ($config === NULL) {
      return self::EXIT_FAILURE;
    }

    // 2. Connect.
    try {
      $cm = new ClientManager();
      $client = $cm->make([
        'host'          => $config['host'],
        'port'          => $config['port'],
        'encryption'    => $config['encryption'],
        'validate_cert' => $config['validate_cert'],
        'username'      => $config['username'],
        'password'      => $config['password'],
        'protocol'      => 'imap',
      ]);
      $client->connect();
    }
    catch (\Throwable $e) {
      $msg = sprintf(
        'WEX IMAP connection failed (user=%s host=%s:%d): %s',
        $config['username'], $config['host'], $config['port'], $e->getMessage()
      );
      $this->output()->writeln("<error>{$msg}</error>");
      $logger->error($msg);
      return self::EXIT_FAILURE;
    }

    $this->output()->writeln(sprintf(
      'Connected as %s to %s:%d (%s). Looking for UNSEEN messages from "%s"…',
      $config['username'],
      $config['host'],
      $config['port'],
      $config['encryption'],
      $config['sender_match']
    ));

    // 3. Open INBOX, query UNSEEN.
    try {
      $inbox = $client->getFolder('INBOX');
      if (!$inbox) {
        throw new \RuntimeException('INBOX folder not accessible.');
      }
      // leaveUnread() prevents the search itself from flipping \Seen.
      // We mark SEEN explicitly per-message based on outcome.
      $messages = $inbox->query()
        ->whereUnseen()
        ->leaveUnread()
        ->get();
    }
    catch (\Throwable $e) {
      $msg = 'WEX IMAP folder/query failed: ' . $e->getMessage();
      $this->output()->writeln("<error>{$msg}</error>");
      $logger->error($msg);
      $this->safelyDisconnect($client);
      return self::EXIT_FAILURE;
    }

    // 4. Filter to messages from the WEX sender.
    $wexMessages = [];
    foreach ($messages as $message) {
      if ($this->messageFromSender($message, $config['sender_match'])) {
        $wexMessages[] = $message;
      }
    }
    // Oldest first — sort by uid asc (uids are monotonic per-mailbox).
    usort($wexMessages, static fn ($a, $b) => ($a->uid ?? 0) <=> ($b->uid ?? 0));

    if (!$wexMessages) {
      $msg = sprintf(
        'No UNSEEN WEX messages found (mailbox total UNSEEN=%d, none matched sender "%s").',
        count($messages),
        $config['sender_match']
      );
      $this->output()->writeln($msg);
      $logger->info($msg);
      $this->safelyDisconnect($client);
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln(sprintf(
      'Found %d UNSEEN WEX message(s) (of %d total UNSEEN). Processing oldest-first…',
      count($wexMessages), count($messages)
    ));

    // 5. Process each WEX message.
    $grand = [
      'messages_processed' => 0,
      'messages_no_url' => 0,
      'messages_fetch_failed' => 0,
      'messages_file_level_error' => 0,
      'empty_windows' => 0,
      'total' => 0,
      'imported' => 0,
      'skipped_duplicate' => 0,
      'errors' => 0,
      'matched' => 0,
      'unmatched_driver' => 0,
      'unmatched_vehicle' => 0,
      'unmatched_both' => 0,
    ];

    foreach ($wexMessages as $message) {
      $this->processMessage($message, $grand, $logger);
    }

    // 6. Disconnect.
    $this->safelyDisconnect($client);

    // 7. Grand summary.
    $summary = sprintf(
      'WEX fetch-email complete — processed=%d, no_url=%d, fetch_failed=%d, file_level_errors=%d, empty_windows=%d. '
      . 'Rows: total=%d, imported=%d, dupes=%d, errors=%d. '
      . 'Match: matched=%d, unmatched_driver=%d, unmatched_vehicle=%d, unmatched_both=%d.',
      $grand['messages_processed'],
      $grand['messages_no_url'],
      $grand['messages_fetch_failed'],
      $grand['messages_file_level_error'],
      $grand['empty_windows'],
      $grand['total'],
      $grand['imported'],
      $grand['skipped_duplicate'],
      $grand['errors'],
      $grand['matched'],
      $grand['unmatched_driver'],
      $grand['unmatched_vehicle'],
      $grand['unmatched_both']
    );
    $this->output()->writeln('');
    $this->output()->writeln($summary);
    $logger->info($summary);

    return self::EXIT_SUCCESS;
  }

  // ──────────────────────────────────────────────────────────────────────
  // Helpers
  // ──────────────────────────────────────────────────────────────────────

  /**
   * Load and validate the wex_imap.* settings block.
   *
   * Returns NULL after printing a clear error if any required key is
   * missing or empty — caller treats NULL as failure exit.
   */
  private function loadConfig(): ?array {
    $raw = Settings::get('wex_imap', []);
    if (!is_array($raw)) {
      $raw = [];
    }

    $required = ['host', 'username', 'password'];
    $missing = [];
    foreach ($required as $k) {
      if (empty($raw[$k]) || !is_string($raw[$k])) {
        $missing[] = "wex_imap.$k";
      }
    }
    if ($missing) {
      $msg = 'WEX fetch-email aborted — missing required settings: ' . implode(', ', $missing)
        . '. Add them to settings.local.php (passwords via getenv).';
      $this->output()->writeln("<error>{$msg}</error>");
      $this->loggerFactory->get('bos_wex_import')->error($msg);
      return NULL;
    }

    return [
      'host'          => $raw['host'],
      'port'          => (int) ($raw['port'] ?? 993),
      'encryption'    => (string) ($raw['encryption'] ?? 'ssl'),
      'validate_cert' => array_key_exists('validate_cert', $raw)
        ? (bool) $raw['validate_cert']
        : TRUE,
      'username'      => $raw['username'],
      'password'      => $raw['password'],
      'sender_match'  => (string) ($raw['sender_match'] ?? 'wexinc.com'),
    ];
  }

  /**
   * Substring-match the message's From address against the configured
   * sender_match. Checks personal/full string AND each address's mail
   * field so a "from-name only" or "envelope-only" message still hits.
   */
  private function messageFromSender(Message $message, string $needle): bool {
    if ($needle === '') {
      return TRUE;
    }
    $needleLower = strtolower($needle);
    $from = $message->getFrom();
    if ($from === NULL) {
      return FALSE;
    }
    foreach ($from->all() as $addr) {
      $candidates = [
        (string) ($addr->mail ?? ''),
        (string) ($addr->full ?? ''),
        (string) $addr,
      ];
      foreach ($candidates as $candidate) {
        if ($candidate !== '' && str_contains(strtolower($candidate), $needleLower)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Process one WEX message: extract URL, fetch CSV, import, then
   * decide read-state. Mutates $grand in place.
   */
  private function processMessage(Message $message, array &$grand, $logger): void {
    $uid = (int) ($message->uid ?? 0);
    $subject = trim((string) ($message->getSubject() ?? ''));
    $tag = sprintf('UID %d "%s"', $uid, $subject !== '' ? $subject : '(no subject)');

    // Body — prefer plaintext; fall back to HTML. The WEX mailer wraps
    // the actual text/plain part inside multipart/related, which makes
    // webklex surface it as an "attachment" rather than via
    // getTextBody(). When the parsed bodies are empty we scan small
    // text/* attachments next, and finally the raw RFC822 body. The
    // URL regex is selective enough (anchored to the WEX endpoint)
    // that picking it up out of raw multipart text is safe.
    $body = $message->hasTextBody() ? (string) $message->getTextBody() : '';
    if ($body === '' && $message->hasHTMLBody()) {
      $body = (string) $message->getHTMLBody();
    }
    if ($body === '') {
      foreach ($message->getAttachments() as $att) {
        $mime = (string) ($att->getMimeType() ?? '');
        if (stripos($mime, 'text/') === 0) {
          $body .= (string) $att->getContent() . "\n";
        }
      }
    }
    if ($body === '') {
      $body = (string) ($message->getRawBody() ?? '');
    }

    if (!preg_match(self::WEX_DOWNLOAD_URL_REGEX, $body, $m)) {
      $msg = "Message {$tag}: no WEX download URL found — leaving UNSEEN for re-inspection.";
      $this->output()->writeln("<comment>{$msg}</comment>");
      $logger->warning($msg);
      $grand['messages_no_url']++;
      return;
    }
    $url = $m[0];
    $this->output()->writeln("Message {$tag}: download URL captured. Fetching…");

    // Temp file under the system temp dir.
    $tempDir = $this->fileSystem->getTempDirectory();
    $tempPath = $tempDir . DIRECTORY_SEPARATOR
      . 'wex_fetch_' . $uid . '_' . bin2hex(random_bytes(6)) . '.csv';

    // Guzzle download.
    try {
      $this->httpClient->request('GET', $url, [
        'sink' => $tempPath,
        'timeout' => 60,
        'connect_timeout' => 15,
        'allow_redirects' => TRUE,
        'http_errors' => TRUE,
      ]);
    }
    catch (\Throwable $e) {
      $msg = "Message {$tag}: HTTP fetch failed — leaving UNSEEN. Error: " . $e->getMessage();
      $this->output()->writeln("<error>{$msg}</error>");
      $logger->error($msg);
      $grand['messages_fetch_failed']++;
      @unlink($tempPath);
      return;
    }

    // Defensive: empty body would parse to zero rows and look like an
    // empty window, but is actually a fetch error worth flagging.
    if (!is_file($tempPath) || filesize($tempPath) === 0) {
      $msg = "Message {$tag}: HTTP fetch produced empty body — leaving UNSEEN.";
      $this->output()->writeln("<error>{$msg}</error>");
      $logger->error($msg);
      $grand['messages_fetch_failed']++;
      @unlink($tempPath);
      return;
    }

    // Hand off to the shared import core.
    $result = $this->importService->importFromFile($tempPath);
    @unlink($tempPath);

    switch ($result['status']) {
      case 'unreadable':
      case 'parse_error':
      case 'missing_headers':
        $msg = "Message {$tag}: file-level import failure — leaving UNSEEN. "
          . $result['message'];
        $this->output()->writeln("<error>{$msg}</error>");
        $logger->error($msg);
        $grand['messages_file_level_error']++;
        // Do NOT mark seen — operator should investigate.
        return;

      case 'empty_window':
        $msg = "Message {$tag}: " . $result['message'];
        $this->output()->writeln($msg);
        $logger->info($msg);
        $grand['empty_windows']++;
        break;

      case 'imported':
      default:
        $perMsg = sprintf('Message %s: %s', $tag, $result['message']);
        $this->output()->writeln($perMsg);
        $logger->info($perMsg);
        // Roll tallies into grand.
        $grand['total']             += $result['total'];
        $grand['imported']          += $result['imported'];
        $grand['skipped_duplicate'] += $result['skipped_duplicate'];
        $grand['errors']            += $result['errors'];
        $grand['matched']           += $result['matched'];
        $grand['unmatched_driver']  += $result['unmatched_driver'];
        $grand['unmatched_vehicle'] += $result['unmatched_vehicle'];
        $grand['unmatched_both']    += $result['unmatched_both'];
        if (!empty($result['error_rows'])) {
          foreach ($result['error_rows'] as $err) {
            $this->output()->writeln(sprintf(
              '  - tx %s: %s', $err['transaction_id'], $err['message']
            ));
          }
        }
        break;
    }

    // Clean run — mark SEEN.
    $grand['messages_processed']++;
    try {
      $message->setFlag('Seen');
    }
    catch (\Throwable $e) {
      // Non-fatal — dedupe still protects against double-import on
      // the next run; just log so the operator can investigate IMAP
      // permission issues if they accumulate.
      $logger->warning(
        'Message {tag}: failed to mark \\Seen ({err}). Dedupe still prevents double-entry.',
        ['tag' => $tag, 'err' => $e->getMessage()]
      );
    }
  }

  private function safelyDisconnect($client): void {
    try {
      $client->disconnect();
    }
    catch (\Throwable $e) {
      // Best-effort cleanup; surface only at debug level.
      $this->loggerFactory->get('bos_wex_import')->debug(
        'IMAP disconnect: ' . $e->getMessage()
      );
    }
  }

}
