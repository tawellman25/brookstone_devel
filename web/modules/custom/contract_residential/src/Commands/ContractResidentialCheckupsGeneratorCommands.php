<?php

namespace Drupal\contract_residential\Commands;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drush\Commands\DrushCommands;

final class ContractResidentialCheckupsGeneratorCommands extends DrushCommands {

  private QueueFactory $queueFactory;
  private LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(QueueFactory $queueFactory, LoggerChannelFactoryInterface $loggerFactory) {
    parent::__construct();
    $this->queueFactory = $queueFactory;
    $this->loggerFactory = $loggerFactory;
  }

/**
 * Enqueue the Check Up generator dispatcher.
 *
 * @command bos:checkups:generate
 * @option force Force enqueue even if already dispatched today.
 * @usage drush bos:checkups:generate
 * @usage drush bos:checkups:generate --force
 */
public function generate(array $options = ['force' => FALSE]) : void {
  $force = !empty($options['force']);

  // Use the module helper so cron + drush share the same guard logic.
  if (function_exists('_contract_residential_checkups_enqueue_dispatch')) {
    _contract_residential_checkups_enqueue_dispatch($force, 'drush');
    $this->logger()->success(
      $force
        ? 'Forced dispatch enqueued.'
        : 'Dispatch enqueued (daily-guarded).'
    );
    return;
  }

  // Fallback: direct enqueue (should not happen).
  $queue = $this->queueFactory->get('contract_residential_checkup_generator');
  $queue->createItem([
    'op' => 'dispatch',
    'queued_at' => \Drupal::time()->getRequestTime(),
    'source' => 'drush',
  ]);

  $this->logger()->success('Dispatch enqueued (fallback).');
  }

}
