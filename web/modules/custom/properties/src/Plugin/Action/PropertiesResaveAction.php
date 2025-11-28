<?php

declare(strict_types=1);

namespace Drupal\properties\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * @property \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
 *
 * Re-save selected Properties entities.
 *
 * @Action(
 *   id = "properties_resave_action",
 *   label = @Translation("Re-save property (trigger presave & related processes)"),
 *   type = "properties"
 * )
 */
final class PropertiesResaveAction extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity instanceof EntityInterface && $entity->getEntityTypeId() === 'properties') {
      $entity->save();
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
