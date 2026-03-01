<?php

namespace Drupal\bos_contract_migrate\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * @MigrateProcessPlugin(
 *   id = "bos_contract_sections_generator"
 * )
 */
class BosContractSectionsGenerator extends ProcessPluginBase {

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $property_id = $row->getSourceProperty('Property_ID');
    $year = $row->getSourceProperty('field_contract_year');
    $contract_key = "{$property_id}:{$year}";

    $services = [
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

    $rows = [];

    foreach ($services as $code => $bundle) {
      $est = $row->getSourceProperty("EST_{$code}");
      $pre = (bool) $row->getSourceProperty("PRE_{$code}");

      if ($est || $pre) {
        $rows[] = [
          'section_key' => "{$contract_key}:{$code}",
          'bundle' => $bundle,
          'estimate' => $est,
          'last_year' => $pre,
          'contract_key' => $contract_key,
        ];
      }
    }

    // Weed spraying fan-out (1277)
    $est = $row->getSourceProperty('EST_1277_weed_spraying');
    $pre = (bool) $row->getSourceProperty('PRE_1277_weed_spraying');

    if ($est || $pre) {
      $rows[] = [
        'section_key' => "{$contract_key}:1277:beds",
        'bundle' => 'weed_spraying_landscape_beds',
        'estimate' => $est,
        'last_year' => $pre,
        'contract_key' => $contract_key,
      ];
      $rows[] = [
        'section_key' => "{$contract_key}:1277:misc",
        'bundle' => 'weed_spraying_of_misc_areas',
        'estimate' => $est,
        'last_year' => $pre,
        'contract_key' => $contract_key,
      ];
    }

    return $rows;
  }
}
