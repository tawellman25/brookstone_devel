<?php

declare(strict_types=1);

namespace Drupal\bos_contract_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\migrate_spreadsheet\Plugin\migrate\source\Spreadsheet;

/**
 * Explodes one contract row into many contract_sections rows.
 *
 * @MigrateSource(
 *   id = "bos_contract_sections_spreadsheet"
 * )
 */
final class BosContractSectionsSpreadsheet extends Spreadsheet {

  private const SERVICE_BUNDLES = [
    '389' => 'aerating_of_lawn',
    '399' => 'aspen_twig_gall_control',
    '396' => 'christmas_decorations',
    '407' => 'cooley_spruce_gall_treatment',
    '406' => 'deciduous_bore_treatment',
    '409' => 'deer_protection_wire',
    '404' => 'dethatching_of_lawn_areas',
    '401' => 'dormant_oil_spray',
    '413' => 'fall_cleanup',
    '417' => 'fertilizing_of_shrubs_and_trees',
    '408' => 'grub_prevention_on_lawn',
    '400' => 'ips_beetle_on_pinion_pine',
    '393' => 'irrigation_check_ups',
    '369' => 'irrigation_shut_down',
    '375' => 'irrigation_start_up',
    '367' => 'lawn_fertilizing',
    '377' => 'lawn_mowing_and_trimming',
    '410' => 'pre_emergent',
    '411' => 'spring_cleanup',
    '412' => 'summer_hedge_shrub_pruning',
    '405' => 'trunk_bore_prevention',
  ];

  /** @var array<int, array<string, mixed>> */
  private array $buffer = [];

  /**
   * {@inheritdoc}
   */

  /**
   * {@inheritdoc}
   */
  public function next(): bool {
    \Drupal::logger('php')->notice('BOS SECTIONS: next() entered');

    // Drain buffered section rows first.
    if (!empty($this->buffer)) {
      $this->currentRow = array_shift($this->buffer);
      return TRUE;
    }

    // Advance spreadsheet base row.
    if (!parent::next()) {
      \Drupal::logger('php')->notice('BOS SECTIONS: parent::next() returned FALSE (no more base rows)');
      return FALSE;
    }

    \Drupal::logger('php')->notice('BOS SECTIONS: parent::next() returned TRUE');

    $base = $this->currentRow;

    // Log the raw keys once so we KNOW what headers exist.
    \Drupal::logger('php')->notice(
      'BOS SECTIONS: BASE ROW KEYS => @keys',
      ['@keys' => implode(', ', array_keys($base))]
    );

    $property_id = (string) ($base['Property_ID'] ?? '');
    $year = (string) ($base['year'] ?? '');

    \Drupal::logger('php')->notice(
      'BOS SECTIONS: Property_ID=@pid year=@yr',
      ['@pid' => $property_id ?: 'MISSING', '@yr' => $year ?: 'MISSING']
    );

    if ($property_id === '' || $year === '') {
      \Drupal::logger('php')->notice('BOS SECTIONS: SKIPPING ROW (missing Property_ID or year)');
      return $this->next();
    }

    $contract_key = $property_id . ':' . $year;

    foreach (self::SERVICE_BUNDLES as $code => $bundle) {
      $est_col = $this->findColumn($base, 'EST_' . $code . '_');
      $pre_col = $this->findColumn($base, 'PRE_' . $code . '_');

      $estimate = $est_col ? trim((string) ($base[$est_col] ?? '')) : '';
      $last_year = $pre_col ? $this->truthy($base[$pre_col] ?? NULL) : FALSE;

      if ($estimate === '' && !$last_year) {
        continue;
      }

      $this->buffer[] = [
        'section_key' => $contract_key . ':' . $code,
        'contract_key' => $contract_key,
        'bundle' => $bundle,
        'estimate' => $estimate,
        'last_year' => (int) $last_year,
      ];
    }

    // Weed spraying (1277) — PRE only, gallon-based.
    $weed_pre = $this->truthy($base['PRE_1277_weed_spraying'] ?? NULL);

    if ($weed_pre) {
      $this->buffer[] = [
        'section_key' => $contract_key . ':1277:beds',
        'contract_key' => $contract_key,
        'bundle' => 'weed_spraying_landscape_beds',
        'estimate' => '',
        'last_year' => 1,
      ];
      $this->buffer[] = [
        'section_key' => $contract_key . ':1277:misc',
        'contract_key' => $contract_key,
        'bundle' => 'weed_spraying_of_misc_areas',
        'estimate' => '',
        'last_year' => 1,
      ];
    }

    if (empty($this->buffer)) {
      \Drupal::logger('php')->notice('BOS SECTIONS: NO SECTIONS GENERATED FOR THIS BASE ROW');
      return $this->next();
    }

    \Drupal::logger('php')->notice('BOS SECTIONS: GENERATED @count SECTION ROWS', [
      '@count' => count($this->buffer),
    ]);

    $this->currentRow = array_shift($this->buffer);
    return TRUE;
  }

  private function findColumn(array $row, string $prefix): ?string {
    foreach ($row as $key => $_) {
      if (is_string($key) && str_starts_with($key, $prefix)) {
        return $key;
      }
    }
    return NULL;
  }

  private function truthy(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    $v = strtolower(trim((string) $value));
    return in_array($v, ['1', 'y', 'yes', 'true', 'checked', 'on', 'x'], TRUE);
  }

  public function prepareRow(Row $row): bool {
    return parent::prepareRow($row);
  }

}
