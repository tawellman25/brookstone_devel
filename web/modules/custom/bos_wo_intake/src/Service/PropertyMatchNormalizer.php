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
   * The street part of whatever someone typed, with city/state/unit noise cut off.
   *
   * People paste a whole address into a street box. On 2026-09-22 a signup typed
   * "1774 Trappers Ct. Delta, Co" for a property stored as "1774 Trappers Ct",
   * the match failed, and a second property record was created for a house that
   * already had two contracts and three work orders on it.
   *
   * A US street address is house number, street name, then a suffix — and
   * anything after the suffix is not the street. So this normalizes and then
   * truncates at the LAST suffix token, keeping "1774 trappers ct" from
   * "1774 trappers ct delta co".
   *
   * The LAST suffix, not the first, so a compound address survives:
   * "N Hillcrest Dr. and Locust St." keeps both halves rather than being cut at
   * "dr". A street with no suffix at all is returned normalized but uncut.
   */
  public function streetCore(string $s): string {
    $tokens = array_values(array_filter(explode(' ', $this->normalizeStreet($s)), static fn($t) => $t !== ''));
    if (!$tokens) {
      return '';
    }
    $suffixes = $this->suffixSet();
    $last = NULL;
    foreach ($tokens as $i => $t) {
      // Never treat the first token as the suffix — "Court 12" is not a street.
      if ($i > 0 && in_array($t, $suffixes, TRUE)) {
        $last = $i;
      }
    }
    if ($last !== NULL) {
      $tokens = array_slice($tokens, 0, $last + 1);
    }
    return implode(' ', $tokens);
  }

  /**
   * Do two streets refer to the same place, once both are cut to their core?
   *
   * Containment runs BOTH ways on purpose. The old code required the stored
   * street to contain the submitted one, which fails the moment somebody types
   * more than the street — the exact shape of the 2026-09-22 duplicate.
   */
  public function streetsMatch(string $submitted, string $stored): bool {
    $a = $this->streetCore($submitted);
    $b = $this->streetCore($stored);
    if ($a === '' || $b === '') {
      return FALSE;
    }
    return $a === $b || str_contains($b, $a) || str_contains($a, $b);
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
