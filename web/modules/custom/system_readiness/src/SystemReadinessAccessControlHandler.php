<?php

namespace Drupal\system_readiness;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

final class SystemReadinessAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer system readiness')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermission($account, 'view system readiness')->cachePerPermissions();

      case 'update':
        return AccessResult::allowedIfHasPermission($account, 'edit system readiness')->cachePerPermissions();

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'administer system readiness')->cachePerPermissions();
    }

    return AccessResult::neutral();
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    if ($account->hasPermission('administer system readiness')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::allowedIfHasPermission($account, 'edit system readiness')->cachePerPermissions();
  }

}
