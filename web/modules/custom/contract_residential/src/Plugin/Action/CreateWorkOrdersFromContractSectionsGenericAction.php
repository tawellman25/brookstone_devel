<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\contract_residential\Service\WorkOrderGenerator;
use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * BOS — Create Work Order(s) from Contract Sections (Generic).
 *
 * @Action(
 *   id = "bos_vbo_create_work_orders_from_contract_sections_generic",
 *   label = @Translation("BOS — Create Work Order(s) from Contract Sections (Generic)"),
 *   type = "contract_sections",
 *   category = @Translation("BOS"),
 *   confirm = TRUE
 * )
 */
final class CreateWorkOrdersFromContractSectionsGenericAction extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface {

  private WorkOrderGenerator $generator;
  private MessengerInterface $bosMessenger;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    WorkOrderGenerator $generator,
    MessengerInterface $messenger
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->generator = $generator;
    $this->bosMessenger = $messenger;

    $this->configuration += [
      'dry_run' => TRUE,
      'fill_multistage_slots' => TRUE,
      'set_work_todo_description' => TRUE,
    ];
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('contract_residential.work_order_generator'),
      $container->get('messenger')
    );
  }

  public function defaultConfiguration(): array {
    return [
      'dry_run' => TRUE,
      'fill_multistage_slots' => TRUE,
      'set_work_todo_description' => TRUE,
    ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry-run (do not create or save anything)'),
      '#default_value' => (bool) ($this->configuration['dry_run'] ?? TRUE),
      '#description' => $this->t('If enabled, validates and reports what would be created, but does not create Work Orders or modify Contract Sections.'),
    ];

    $form['fill_multistage_slots'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fill multi-stage Work Order pointer fields (2nd/3rd/4th) when primary is already set'),
      '#default_value' => (bool) ($this->configuration['fill_multistage_slots'] ?? TRUE),
      '#description' => $this->t('If enabled, populate first empty of field_2nd_work_order / field_3rd_work_order / field_4th_work_order (only if the section bundle has those fields). Primary field_work_order is never overwritten.'),
    ];

    $form['set_work_todo_description'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set Work To-Do Description on created Work Orders'),
      '#default_value' => (bool) ($this->configuration['set_work_todo_description'] ?? TRUE),
      '#description' => $this->t('If enabled, sets a consistent BOS description with year + service label + detected season (when available).'),
      '#states' => [
        'disabled' => [
          ':input[name="dry_run"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form + parent::buildConfigurationForm($form, $form_state);
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['dry_run'] = (bool) $form_state->getValue('dry_run');
    $this->configuration['fill_multistage_slots'] = (bool) $form_state->getValue('fill_multistage_slots');
    $this->configuration['set_work_todo_description'] = (bool) $form_state->getValue('set_work_todo_description');
    parent::submitConfigurationForm($form, $form_state);
  }

  public function executeMultiple(array $entities) {
    $opts = [
      'dry_run' => !empty($this->configuration['dry_run']),
      'fill_multistage_slots' => !empty($this->configuration['fill_multistage_slots']),
      'set_work_todo_description' => !empty($this->configuration['set_work_todo_description']),
    ];

    $aggregate = new WorkOrderGenerationResult($opts['dry_run']);

    foreach ($entities as $entity) {
      if (!$entity instanceof EntityInterface || $entity->getEntityTypeId() !== 'contract_sections') {
        $aggregate->addSkipped();
        continue;
      }

      $r = $this->generator->generateFromSection($entity, $opts);
      $this->mergeResult($aggregate, $r);
    }

    $this->postSummary($aggregate);
    return $entities;
  }

  public function execute(EntityInterface $entity = NULL) {
    $opts = [
      'dry_run' => !empty($this->configuration['dry_run']),
      'fill_multistage_slots' => !empty($this->configuration['fill_multistage_slots']),
      'set_work_todo_description' => !empty($this->configuration['set_work_todo_description']),
    ];

    $aggregate = new WorkOrderGenerationResult($opts['dry_run']);

    if ($entity instanceof EntityInterface && $entity->getEntityTypeId() === 'contract_sections') {
      $r = $this->generator->generateFromSection($entity, $opts);
      $this->mergeResult($aggregate, $r);
    }
    else {
      $aggregate->addSkipped();
      $aggregate->addMessage('Selected row is not a Contract Section.');
    }

    $this->postSummary($aggregate);
    return $entity;
  }

  private function mergeResult(WorkOrderGenerationResult $into, WorkOrderGenerationResult $from): void {
    // Note: WorkOrderGenerationResult is simple counters + messages; merge manually.
    for ($i = 0; $i < $from->getCreated(); $i++) {
      $into->addCreated();
    }
    for ($i = 0; $i < $from->getWouldCreate(); $i++) {
      $into->addWouldCreate();
    }
    for ($i = 0; $i < $from->getSkipped(); $i++) {
      $into->addSkipped();
    }
    foreach ($from->getMessages() as $m) {
      $into->addMessage($m);
    }
  }

  private function postSummary(WorkOrderGenerationResult $r): void {
    if ($r->isDryRun()) {
      $this->bosMessenger->addStatus($this->t(
        'BOS WO dry-run complete. Would create: @w. Skipped: @s.',
        ['@w' => $r->getWouldCreate(), '@s' => $r->getSkipped()]
      ));
    }
    else {
      $this->bosMessenger->addStatus($this->t(
        'BOS WO creation complete. Created: @c. Skipped: @s.',
        ['@c' => $r->getCreated(), '@s' => $r->getSkipped()]
      ));
    }

    // Show up to 25 messages (warnings + errors included).
    $max = 25;
    $msgs = array_slice($r->getMessages(), 0, $max);
    foreach ($msgs as $msg) {
      // Treat "ERROR:" as error; otherwise warning.
      if (strpos($msg, 'ERROR:') === 0) {
        $this->bosMessenger->addError($msg);
      }
      else {
        $this->bosMessenger->addWarning($msg);
      }
    }
    if (count($r->getMessages()) > $max) {
      $this->bosMessenger->addWarning($this->t('Additional messages omitted: @n more.', [
        '@n' => count($r->getMessages()) - $max,
      ]));
    }
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
