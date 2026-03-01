<?php

declare(strict_types=1);

namespace Drupal\estimate\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\estimate\Service\WorkOrderConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk action: Convert selected Estimates to Work Orders.
 *
 * This action is safe and idempotent:
 * - It will report errors when guardrails fail (not Accepted, not current,
 *   already linked, missing contact, etc.).
 *
 * @Action(
 *   id = "estimate_convert_selected_estimates_to_work_orders",
 *   label = @Translation("Convert selected Estimates to Work Orders"),
 *   type = "estimate"
 * )
 */
final class ConvertSelectedEstimatesToWorkOrders extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly WorkOrderConverter $converter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('estimate.work_order_converter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity || $entity->getEntityTypeId() !== 'estimate') {
      return;
    }

    try {
      $wo = $this->converter->convert($entity);
      $this->messenger()->addStatus($this->t('Converted Estimate @eid to Work Order @wid.', [
        '@eid' => $entity->id(),
        '@wid' => $wo->id(),
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Estimate @eid failed conversion: @msg', [
        '@eid' => $entity->id(),
        '@msg' => $e->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
