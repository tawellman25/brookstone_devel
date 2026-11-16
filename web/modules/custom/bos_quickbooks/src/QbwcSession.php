<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Web Connector sessions.
 *
 * The connector authenticates with the user name from the .qwc file and the
 * password set on the settings form (stored as a hash in State, never in
 * config). A successful authenticate() opens ONE session — a ticket the
 * connector quotes on every later call. The connector runs one update at a
 * time, so a second authenticate while a session is live is answered "none"
 * (nothing to do); a session nobody has touched for STALE_SESSION_SECONDS is
 * treated as abandoned and replaced.
 *
 * Also owns the two GUIDs the .qwc file needs (OwnerID identifies the app,
 * FileID the company file). Generated once and kept in State: changing
 * either means re-adding the application in the Web Connector.
 */
final class QbwcSession {

  public const STATE_SESSION = 'bos_quickbooks.session';
  public const STATE_PASSWORD = 'bos_quickbooks.password_hash';
  public const STATE_OWNER_ID = 'bos_quickbooks.owner_id';
  public const STATE_FILE_ID = 'bos_quickbooks.file_id';
  public const STATE_LAST_CONTACT = 'bos_quickbooks.last_contact';
  public const STATE_LAST_COMPANY = 'bos_quickbooks.last_company';
  public const FLOOD_EVENT = 'bos_quickbooks.auth_failure';
  public const FLOOD_LIMIT = 10;
  public const FLOOD_WINDOW = 3600;
  public const STALE_SESSION_SECONDS = 600;

  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
    private readonly PasswordInterface $password,
    private readonly UuidInterface $uuid,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The user name the .qwc file carries.
   */
  public function username(): string {
    return (string) ($this->configFactory->get('bos_quickbooks.settings')->get('username') ?: 'bos');
  }

  public function hasPassword(): bool {
    return (bool) $this->state->get(self::STATE_PASSWORD);
  }

  public function setPassword(string $plain): void {
    $this->state->set(self::STATE_PASSWORD, $this->password->hash($plain));
    $this->logger->notice('Web Connector password set.');
  }

  /**
   * Answers the connector's authenticate() call.
   *
   * @return string[]
   *   [ticket, status]: status '' = proceed (or the configured company-file
   *   path), 'none' = valid login but nothing to do right now, 'nvu' = invalid
   *   user — the connector shows that to the user and stops.
   */
  public function authenticate(?string $user, ?string $pass, string $ip): array {
    $user = (string) $user;
    $pass = (string) $pass;
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $ip)) {
      $this->logger->warning('Web Connector authenticate blocked by flood control from @ip.', ['@ip' => $ip]);
      return ['', 'nvu'];
    }
    $hash = (string) $this->state->get(self::STATE_PASSWORD);
    $ok = $hash !== '' && hash_equals($this->username(), $user) && $this->password->check($pass, $hash);
    if (!$ok) {
      $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $ip);
      $this->logger->warning('Web Connector authenticate FAILED for user "@user" from @ip.', ['@user' => $user, '@ip' => $ip]);
      return ['', 'nvu'];
    }
    $this->flood->clear(self::FLOOD_EVENT, $ip);
    $now = $this->time->getRequestTime();
    $this->state->set(self::STATE_LAST_CONTACT, $now);

    $current = $this->state->get(self::STATE_SESSION);
    if (is_array($current) && !empty($current['ticket']) && ($now - (int) ($current['last_seen'] ?? 0)) < self::STALE_SESSION_SECONDS) {
      $this->logger->notice('Web Connector authenticate while session @ticket is live (last seen @ago s ago) — answered "none".', [
        '@ticket' => substr((string) $current['ticket'], 0, 8),
        '@ago' => $now - (int) $current['last_seen'],
      ]);
      return ['', 'none'];
    }
    if (is_array($current) && !empty($current['ticket'])) {
      $this->logger->warning('Web Connector session @ticket abandoned (last seen @when) — replaced.', [
        '@ticket' => substr((string) $current['ticket'], 0, 8),
        '@when' => date('c', (int) ($current['last_seen'] ?? 0)),
      ]);
    }
    $ticket = bin2hex(random_bytes(16));
    $session = [
      'ticket' => $ticket,
      'ip' => $ip,
      'started' => $now,
      'last_seen' => $now,
      'company_file' => '',
      'company_name' => '',
      'qbxml_version' => '',
      'requests' => 0,
      'jobs' => [],
      'current' => 0,
      'last_error' => '',
    ];
    $this->state->set(self::STATE_SESSION, $session);
    $company = (string) $this->configFactory->get('bos_quickbooks.settings')->get('company_file');
    $this->logger->info('Web Connector session @ticket opened from @ip.', ['@ticket' => substr($ticket, 0, 8), '@ip' => $ip]);
    return [$ticket, $company];
  }

  /**
   * The live session for a ticket, with last_seen touched — or NULL.
   */
  public function load(?string $ticket): ?array {
    $ticket = (string) $ticket;
    $session = $this->state->get(self::STATE_SESSION);
    if (!is_array($session) || $ticket === '' || !hash_equals((string) ($session['ticket'] ?? ''), $ticket)) {
      return NULL;
    }
    $session['last_seen'] = $this->time->getRequestTime();
    return $session;
  }

  /**
   * The current session whatever its ticket (status page).
   */
  public function current(): ?array {
    $session = $this->state->get(self::STATE_SESSION);
    return is_array($session) && !empty($session['ticket']) ? $session : NULL;
  }

  public function save(array $session): void {
    $this->state->set(self::STATE_SESSION, $session);
  }

  public function close(array $session, string $reason): void {
    $this->logger->info('Web Connector session @ticket closed (@reason) after @n request(s), @s s.', [
      '@ticket' => substr((string) $session['ticket'], 0, 8),
      '@reason' => $reason,
      '@n' => (int) ($session['requests'] ?? 0),
      '@s' => $this->time->getRequestTime() - (int) ($session['started'] ?? $this->time->getRequestTime()),
    ]);
    $this->state->delete(self::STATE_SESSION);
  }

  /**
   * Remembers which company file the connector reported (name + path).
   */
  public function recordCompany(string $file, string $name, string $product = ''): void {
    $this->state->set(self::STATE_LAST_COMPANY, [
      'file' => $file,
      'name' => $name,
      'product' => $product,
      'at' => $this->time->getRequestTime(),
    ]);
  }

  public function lastCompany(): ?array {
    $v = $this->state->get(self::STATE_LAST_COMPANY);
    return is_array($v) ? $v : NULL;
  }

  public function lastContact(): ?int {
    $v = $this->state->get(self::STATE_LAST_CONTACT);
    return $v === NULL ? NULL : (int) $v;
  }

  /**
   * OwnerID for the .qwc file — a braces GUID, generated once.
   */
  public function ownerId(): string {
    return $this->guid(self::STATE_OWNER_ID);
  }

  /**
   * FileID for the .qwc file — a braces GUID, generated once.
   */
  public function fileId(): string {
    return $this->guid(self::STATE_FILE_ID);
  }

  private function guid(string $key): string {
    $v = (string) $this->state->get($key);
    if ($v === '') {
      $v = '{' . strtoupper($this->uuid->generate()) . '}';
      $this->state->set($key, $v);
    }
    return $v;
  }

}
