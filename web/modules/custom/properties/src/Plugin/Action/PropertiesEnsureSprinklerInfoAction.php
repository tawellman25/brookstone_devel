<?php

declare(strict_types=1);

namespace Drupal\properties\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * @property \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
 *
 * Ensures each selected Property has a related property_sprinkler_info entity.
 *
 * @Action(
 *   id = "properties_ensure_sprinkler_info_action",
 *   label = @Translation("Ensure sprinkler info (create if missing)"),
 *   type = "properties"
 * )
 */
final class PropertiesEnsureSprinklerInfoAction extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity instanceof EntityInterface && $entity->getEntityTypeId() === 'properties') {
      _properties_ensure_property_sprinkler_info($entity);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $object instanceof EntityInterface
      ? $object->access('update', $account, TRUE)
      : $this->entityTypeManager
        ->getAccessControlHandler('properties')
        ->createAccess(NULL, $account, [], TRUE);

    return $return_as_object ? $result : $result->isAllowed();
  }

}
