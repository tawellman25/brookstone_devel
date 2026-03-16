<?php

declare(strict_types=1);

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Removes a property from the weed spray route.
 *
 * @Action(
 *   id = "spray_route_remove_action",
 *   label = @Translation("Remove from Weed Spray Route"),
 *   type = "property_spraying_info",
 *   confirm = TRUE,
 * )
 */
class SprayRouteRemoveAction extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity || $entity->bundle() !== 'weed_spraying') {
      return;
    }

    $entity->set('field_spray_route', FALSE);
    $entity->set('field_weed_beds_contracted', FALSE);
    $entity->set('field_weed_misc_contracted', FALSE);
    $entity->set('field_active_contract_year', NULL);
    $entity->save();

    \Drupal::logger('contract_residential')->notice(
      'Property @pid removed from weed spray route via bulk action.',
      ['@pid' => $entity->get('field_property')->target_id]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $object->access('update', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
