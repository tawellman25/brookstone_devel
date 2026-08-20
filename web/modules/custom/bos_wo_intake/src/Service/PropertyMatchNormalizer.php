<?php

declare(strict_types=1);

namespace Drupal\bos_wo_intake\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Shared property-matching normalizers.
 *
 * Extracted verbatim from WorkOrderIntakeService's private helpers so a second
 * intake surface (bos_service_request's public matcher) uses the SAME text /
 * street / token rules and the same street_suffix_map — the "one normalizer"
 * rule. WorkOrderIntakeService now delegates to this service; its behavior is
 * unchanged.
 */
final class PropertyMatchNormalizer {

  private $settings;

  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->settings = $configFactory->get('bos_wo_intake.settings');
  }

  /**
   * Lowercase, strip non-alphanumerics to spaces, collapse whitespace.
   */
  public function normalizeText(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
  }

  /**
   * Normalize + apply street_suffix_map token equivalence (rd→road, etc.).
   */
  public function normalizeStreet(string $s): string {
    $map = $this->settings->get('street_suffix_map') ?? [];
    $tokens = explode(' ', $this->normalizeText($s));
    foreach ($tokens as &$t) {
      if (isset($map[$t])) {
        $t = $map[$t];
      }
    }
    return trim(implode(' ', $tokens));
  }

  /**
   * All suffix tokens (both abbreviations and canonical forms).
   */
  public function suffixSet(): array {
    $map = $this->settings->get('street_suffix_map') ?? [];
    return array_values(array_unique(array_merge(array_keys($map), array_values($map))));
  }

  /**
   * Strip a single trailing possessive/plural "s" from a meaningful-length token.
   */
  public function stem(string $t): string {
    return (strlen($t) > 3 && substr($t, -1) === 's') ? substr($t, 0, -1) : $t;
  }

  /**
   * Does a name token appear in the (normalized) nickname? Substring + stem.
   */
  public function tokenMatches(string $nick, string $token): bool {
    if (str_contains($nick, $token)) {
      return TRUE;
    }
    $stem = $this->stem($token);
    return $stem !== $token && str_contains($nick, $stem);
  }

}
