<?php

namespace Drupal\estimate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\config_pages\ConfigPagesLoaderServiceInterface;

/**
 * Resolves Sq Ft Break Point rates referenced from Business Settings.
 *
 * Breakpoint entities are expected to have:
 * - field_min_sq_ft (int)
 * - field_max_sq_ft (int)
 * - field_rate (decimal)
 */
final class SqFtBreakPointResolver {

  public function __construct(
    private readonly ConfigPagesLoaderServiceInterface $configPagesLoader,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the breakpoint rate for a given sq ft from Business Settings field.
   *
   * @param int $sq_ft
   *   Area in square feet. Must be > 0.
   * @param string $business_settings_type
   *   Config Pages type machine name, e.g. "business_settings".
   * @param string $field_name
   *   Entity reference field on business_settings that points to sq_ft_break_points,
   *   e.g. "field_overseeding_labor" or "field_overseeding_seed_markup".
   *
   * @return string
   *   Decimal string rate (as stored in field_rate).
   *
   * @throws \InvalidArgumentException
   * @throws \RuntimeException
   */
  public function resolveRate(int $sq_ft, string $business_settings_type, string $field_name): string {
    if ($sq_ft <= 0) {
      throw new \InvalidArgumentException("Sq ft must be > 0. Got: {$sq_ft}");
    }

    $settings = $this->configPagesLoader->load($business_settings_type);
    if (!$settings) {
      throw new \RuntimeException("Business Settings config page '{$business_settings_type}' could not be loaded.");
    }
    if (!$settings->hasField($field_name)) {
      throw new \RuntimeException("Business Settings is missing expected field '{$field_name}'.");
    }

    /** @var \Drupal\Core\Entity\EntityInterface[] $bps */
    $bps = $settings->get($field_name)->referencedEntities();
    if (empty($bps)) {
      throw new \RuntimeException("No breakpoints configured in '{$business_settings_type}.{$field_name}'.");
    }

    // Defensive sort: ascending by min sq ft.
    usort($bps, static function ($a, $b) {
      $amin = (int) ($a->get('field_min_sq_ft')->value ?? 0);
      $bmin = (int) ($b->get('field_min_sq_ft')->value ?? 0);
      return $amin <=> $bmin;
    });

    // Optional hardening: detect overlapping ranges.
    $this->validateNoOverlaps($bps, $business_settings_type, $field_name);

    foreach ($bps as $bp) {
      $min = (int) ($bp->get('field_min_sq_ft')->value ?? 0);
      $max = (int) ($bp->get('field_max_sq_ft')->value ?? 0);

      if ($min <= $sq_ft && $sq_ft <= $max) {
        $rate = (string) ($bp->get('field_rate')->value ?? '');
        if ($rate === '') {
          throw new \RuntimeException("Matched breakpoint has empty field_rate for '{$business_settings_type}.{$field_name}'.");
        }
        return $rate;
      }
    }

    throw new \RuntimeException("No breakpoint match for {$sq_ft} sq ft in '{$business_settings_type}.{$field_name}'.");
  }

  /**
   * Logs and throws on overlapping ranges (strongly recommended).
   *
   * @param array $bps
   * @param string $type
   * @param string $field
   */
  private function validateNoOverlaps(array $bps, string $type, string $field): void {
    $prev_max = NULL;

    foreach ($bps as $bp) {
      $min = (int) ($bp->get('field_min_sq_ft')->value ?? 0);
      $max = (int) ($bp->get('field_max_sq_ft')->value ?? 0);

      if ($min <= 0 || $max <= 0 || $min > $max) {
        $msg = "Invalid breakpoint range in '{$type}.{$field}': min={$min}, max={$max}.";
        $this->loggerFactory->get('estimate')->error($msg);
        throw new \RuntimeException($msg);
      }

      if ($prev_max !== NULL && $min <= $prev_max) {
        $msg = "Overlapping breakpoint ranges detected in '{$type}.{$field}': min={$min} overlaps prev_max={$prev_max}.";
        $this->loggerFactory->get('estimate')->error($msg);
        throw new \RuntimeException($msg);
      }

      $prev_max = $max;
    }
  }

}
