<?php

namespace Drupal\contract_residential\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for contract_residential.
 */
final class ContractResidentialCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct();
  }

  /**
   * Backfill contract_sections.field_contract from contracts:residential slot fields.
   *
   * Purpose:
   * - Legacy residential Contracts referenced contract_sections via per-section
   *   slot fields on the Contract.
   * - Newer workflows/views use contract_sections.field_contract.
   * - This command backfills field_contract on legacy contract_sections so Views
   *   filtering by field_contract work for older Contracts.
   *
   * Safety rules:
   * - Only sets field_contract when it is empty.
   * - Never overwrites an existing different value; logs a conflict instead.
   * - Logs missing section references.
   *
   * @command bos:contracts:sections-backfill
   * @aliases bos-cs-backfill
   *
   * @option dry-run
   *   Do not save changes; report what would change.
   * @option limit
   *   Max contracts to process (0 = no limit).
   * @option start-id
   *   Process contracts with id >= this value.
   * @option contract-id
   *   Process a single contract id.
   *
   * @usage drush bos:contracts:sections-backfill --dry-run
   * @usage drush bos:contracts:sections-backfill --limit=200
   * @usage drush bos:contracts:sections-backfill --contract-id=4199
   */
  public function backfillContractSectionContractPointer(array $options = [
    'dry-run' => FALSE,
    'limit' => 0,
    'start-id' => 0,
    'contract-id' => 0,
  ]): int {

    $dry_run = (bool) ($options['dry-run'] ?? FALSE);
    $limit = (int) ($options['limit'] ?? 0);
    $start_id = (int) ($options['start-id'] ?? 0);
    $contract_id = (int) ($options['contract-id'] ?? 0);

    $contract_storage = $this->entityTypeManager->getStorage('contracts');
    $section_storage = $this->entityTypeManager->getStorage('contract_sections');
    $log = $this->loggerFactory->get('contract_residential');

    $slot_fields = $this->getResidentialSectionSlotFields();

    $query = $contract_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'residential')
      ->sort('id', 'ASC');

    if ($start_id > 0) {
      $query->condition('id', $start_id, '>=');
    }
    if ($contract_id > 0) {
      $query->condition('id', $contract_id);
    }
    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $cids = $query->execute();
    if (!$cids) {
      $this->output()->writeln('No matching residential contracts found.');
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln(sprintf(
      'Processing %d contract(s)%s...',
      count($cids),
      $dry_run ? ' (DRY RUN)' : ''
    ));

    $stats = [
      'contracts' => 0,
      'sections_examined' => 0,
      'sections_updated' => 0,
      'conflicts' => 0,
      'missing_sections' => 0,
      'sections_without_field_contract' => 0,
    ];

    $contracts = $contract_storage->loadMultiple($cids);

    foreach ($contracts as $contract) {
      $stats['contracts']++;

      $section_ids = [];

      foreach ($slot_fields as $field_name) {
        if (!$contract->hasField($field_name) || $contract->get($field_name)->isEmpty()) {
          continue;
        }

        $target_id = (int) $contract->get($field_name)->target_id;
        if ($target_id > 0) {
          $section_ids[] = $target_id;
        }
      }

      $section_ids = array_values(array_unique($section_ids));
      if (!$section_ids) {
        continue;
      }

      $sections = $section_storage->loadMultiple($section_ids);

      foreach ($section_ids as $sid) {
        $stats['sections_examined']++;

        if (!isset($sections[$sid])) {
          $stats['missing_sections']++;
          $msg = sprintf(
            'Contract %d references missing contract_section %d.',
            (int) $contract->id(),
            $sid
          );
          $this->output()->writeln("<comment>$msg</comment>");
          $log->warning($msg);
          continue;
        }

        $section = $sections[$sid];

        if (!$section->hasField('field_contract')) {
          $stats['sections_without_field_contract']++;
          $msg = sprintf(
            'contract_section %d has no field_contract field (schema drift).',
            (int) $section->id()
          );
          $this->output()->writeln("<error>$msg</error>");
          $log->error($msg);
          continue;
        }

        $existing = !$section->get('field_contract')->isEmpty()
          ? (int) $section->get('field_contract')->target_id
          : 0;

        $wanted = (int) $contract->id();

        if ($existing === 0) {
          $stats['sections_updated']++;
          $msg = sprintf(
            'SET section %d field_contract => %d',
            (int) $section->id(),
            $wanted
          );
          $this->output()->writeln($dry_run ? "[DRY] $msg" : $msg);

          if (!$dry_run) {
            $section->set('field_contract', $wanted);
            $section->save();
          }
        }
        elseif ($existing !== $wanted) {
          $stats['conflicts']++;
          $msg = sprintf(
            'CONFLICT section %d field_contract=%d but referenced by contract %d (not overwriting).',
            (int) $section->id(),
            $existing,
            $wanted
          );
          $this->output()->writeln("<comment>$msg</comment>");
          $log->warning($msg);
        }
      }
    }

    $this->output()->writeln('');
    $this->output()->writeln('Done. Summary:');
    $this->output()->writeln(sprintf('  Contracts processed:              %d', $stats['contracts']));
    $this->output()->writeln(sprintf('  Sections examined:                %d', $stats['sections_examined']));
    $this->output()->writeln(sprintf('  Sections updated:                 %d', $stats['sections_updated']));
    $this->output()->writeln(sprintf('  Conflicts (not changed):          %d', $stats['conflicts']));
    $this->output()->writeln(sprintf('  Missing section entities:         %d', $stats['missing_sections']));
    $this->output()->writeln(sprintf('  Sections missing field_contract:  %d', $stats['sections_without_field_contract']));

    if ($dry_run) {
      $this->output()->writeln('');
      $this->output()->writeln('<comment>Dry-run mode: no changes were saved.</comment>');
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Residential contract section slot fields (contracts:residential -> contract_sections).
   *
   * Source of truth: BOS Contracts entity spec.
   *
   * @return string[]
   *   Field machine names on contracts:residential that reference contract_sections.
   */
  private function getResidentialSectionSlotFields(): array {
    return [
      'field_aerating_of_lawn',
      'field_aspen_twig_gall_control',
      'field_christmas_decorations',
      'field_cooley_spruce_gall_treatme',
      'field_deciduous_bore_treatment',
      'field_deer_protection_wire_for_t',
      'field_dethatching_of_lawn_areas',
      'field_dormant_oil_spray',
      'field_fall_cleanup',
      'field_fertilizing_trees_shrubs',
      'field_grub_prevention_on_lawn',
      'field_ips_beetle_on_pinion_pine',
      'field_irrigation_check_ups',
      'field_irrigation_shut_down',
      'field_irrigation_start_up',
      'field_lawn_fertilizing_broadleaf',
      'field_lawn_mowing_and_trimming',
      'field_pre_emergent',
      'field_spring_cleanup',
      'field_summer_hedge_shrub_pruning',
      'field_trunk_bore_prevention',
      'field_weed_spraying_of_landscape',
      'field_weed_spraying_of_misc_area',
    ];
  }

}
