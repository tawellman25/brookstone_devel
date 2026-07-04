<?php

declare(strict_types=1);

namespace Drupal\bos_wo_intake\Authentication\Provider;

use Drupal\Core\Authentication\AuthenticationProviderChallengeInterface;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * API-key authentication for the Cowork Connect WO-intake endpoint.
 *
 * CRITICAL — the secret is read from the custom `X-API-KEY` header, NOT
 * `Authorization: Bearer`. The live CloudLinux/LiteSpeed SAPI strips the
 * `Authorization` header before it reaches PHP (the same SAPI nuance that broke
 * the WEX cron — see drupal_bos_gotchas.md). `X-API-KEY` is also exactly the
 * header shape Copilot Cowork's OpenAPI API-key auth emits. Do not change this.
 *
 * The stored secret comes from the `bos_wo_intake` key entity, whose `env`
 * provider reads the BOS_WO_INTAKE_API_KEY environment variable. Because this
 * runs in the WEB SAPI (not cron/CLI like WEX), that variable must be exported
 * to the web process — set it via putenv() in settings.php (off-git) per
 * environment. The secret never lives in git or Drupal config.
 */
final class CoworkKeyAuthProvider implements AuthenticationProviderInterface, AuthenticationProviderChallengeInterface {

  private const HEADER = 'X-API-KEY';
  private const KEY_ID = 'bos_wo_intake';
  private const ACCOUNT_NAME = 'cowork-connect';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request): bool {
    return $request->headers->has(self::HEADER);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request) {
    $provided = (string) $request->headers->get(self::HEADER, '');

    $key = $this->keyRepository->getKey(self::KEY_ID);
    $secret = $key ? (string) $key->getKeyValue() : '';

    // Fail closed if the secret is unset (misconfigured env). Never treat an
    // empty configured secret as a match.
    if ($secret === '' || $provided === '') {
      if ($secret === '') {
        $this->logger->error('WO-intake auth: no secret available (BOS_WO_INTAKE_API_KEY unset or key missing).');
      }
      return NULL;
    }

    // Constant-time comparison — no early-exit timing leak.
    if (!hash_equals($secret, $provided)) {
      return NULL;
    }

    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => self::ACCOUNT_NAME]);
    $account = $accounts ? reset($accounts) : NULL;

    if (!$account || !$account->isActive()) {
      $this->logger->error('WO-intake auth: service account "@n" missing or blocked.', ['@n' => self::ACCOUNT_NAME]);
      return NULL;
    }

    return $account;
  }

  /**
   * {@inheritdoc}
   *
   * When our header was present but auth failed (bad key / blocked account),
   * turn the resulting access-denied into a proper 401 rather than a 403.
   */
  public function challengeException(Request $request, \Exception $previous) {
    return new UnauthorizedHttpException('X-API-KEY', 'Invalid or missing API key.', $previous);
  }

}
