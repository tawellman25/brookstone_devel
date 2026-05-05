<?php

declare(strict_types=1);

namespace Drupal\bos_wex_import\Batch;

use Drupal\Core\Url;

/**
 * Batch operation callbacks for WEX fuel transaction import.
 *
 * Static methods because Drupal's Batch API stores operation specs as
 * serializable arrays of [callable, args]; an instance + dependency
 * chain wouldn't survive the serialize round-trip cleanly.
 */
final class WexFuelImportBatch {

  /**
   * Process a single parsed row.
   *
   * @param array $row     The parsed CSV/XLSX row, keyed by column name.
   * @param int $index     1-based index for progress messaging.
   * @param int $total     Total rows being imported.
   * @param array $context Drupal Batch API context, modified in place.
   */
  public static function processRow(array $row, int $index, int $total, array &$context): void {
    if (!isset($context['results']['imported'])) {
      foreach (['imported', 'duplicates', 'errors',
                'matched', 'unmatched_driver', 'unmatched_vehicle', 'unmatched_both'] as $k) {
        $context['results'][$k] = 0;
      }
      $context['results']['error_messages'] = [];
    }

    /** @var \Drupal\bos_wex_import\Service\WexFuelImportService $svc */
    $svc = \Drupal::service('bos_wex_import.import_service');
    $result = $svc->importRow($row);

    switch ($result['status']) {
      case 'imported':
        $context['results']['imported']++;
        $ms = $result['match_status'] ?? 'matched';
        if (isset($context['results'][$ms])) {
          $context['results'][$ms]++;
        }
        break;

      case 'skipped_duplicate':
        $context['results']['duplicates']++;
        break;

      case 'error':
      default:
        $context['results']['errors']++;
        if (!empty($result['message'])) {
          $context['results']['error_messages'][] = sprintf(
            'Row %d (tx %s): %s',
            $index,
            $result['transaction_id'] ?: '(empty)',
            $result['message']
          );
        }
        break;
    }

    $context['message'] = sprintf(
      'Processing transaction %s (%d of %d)…',
      $result['transaction_id'] ?: '(unknown)',
      $index,
      $total
    );
  }

  /**
   * Batch finished callback. Emits summary message + redirects to list.
   */
  public static function finished(bool $success, array $results, array $operations): \Symfony\Component\HttpFoundation\RedirectResponse {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(t('Import did not complete successfully. See watchdog for details.'));
    }
    else {
      $imported = $results['imported'] ?? 0;
      $duplicates = $results['duplicates'] ?? 0;
      $errors = $results['errors'] ?? 0;
      $matched = $results['matched'] ?? 0;
      $unD = $results['unmatched_driver'] ?? 0;
      $unV = $results['unmatched_vehicle'] ?? 0;
      $unB = $results['unmatched_both'] ?? 0;

      $summary = t(
        'Import complete: @imported imported, @dup duplicates skipped, @err errors. Match status: @m matched, @ud unmatched drivers, @uv unmatched vehicles, @ub both.',
        [
          '@imported' => $imported,
          '@dup' => $duplicates,
          '@err' => $errors,
          '@m' => $matched,
          '@ud' => $unD,
          '@uv' => $unV,
          '@ub' => $unB,
        ]
      );
      $messenger->addStatus($summary);

      $unmatchedTotal = $unD + $unV + $unB;
      if ($unmatchedTotal > 0) {
        $reviewUrl = Url::fromRoute('view.equipment_fuel_transactions_unmatched.page_1');
        $messenger->addWarning(t(
          '@n unmatched transactions need manual resolution. <a href="@url">Review Queue</a>.',
          ['@n' => $unmatchedTotal, '@url' => $reviewUrl->toString()]
        ));
      }

      // Surface the first few error rows directly (don't bury them in logs only).
      if (!empty($results['error_messages'])) {
        $top = array_slice($results['error_messages'], 0, 5);
        $messenger->addError(t(
          'Import errors (first @n shown, see watchdog for any additional): @msgs',
          ['@n' => count($top), '@msgs' => implode(' | ', $top)]
        ));
      }
    }

    // Redirect to the master list view.
    $listUrl = Url::fromRoute('view.equipment_fuel_transactions_admin.page_1')->toString();
    return new \Symfony\Component\HttpFoundation\RedirectResponse($listUrl);
  }

}
