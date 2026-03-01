<?php

declare(strict_types=1);

namespace Drupal\estimate\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access checks for Estimate Request -> Contact operations.
 */
final class EstimateRequestContactAccess {

  public static function accessCreate(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    /** @var \Drupal\Core\Entity\EntityInterface|null $req */
    $req = $route_match->getParameter('estimate_request');
    if (!$req instanceof EntityInterface) {
      return AccessResult::forbidden();
    }

    // Must be able to view/update estimate request to attach a contact.
    $ok = $req->access('update', $account);

    // If already linked, no need to create.
    if ($ok && $req->hasField('field_contact') && !$req->get('field_contact')->isEmpty()) {
      return AccessResult::forbidden();
    }

    return $ok ? AccessResult::allowed() : AccessResult::forbidden();
  }

  public static function accessLink(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    /** @var \Drupal\Core\Entity\EntityInterface|null $req */
    $req = $route_match->getParameter('estimate_request');
    /** @var \Drupal\Core\Entity\EntityInterface|null $contact */
    $contact = $route_match->getParameter('contact');

    if (!$req instanceof EntityInterface || !$contact instanceof EntityInterface) {
      return AccessResult::forbidden();
    }

    // Must be able to update request and view contact.
    if (!$req->access('update', $account) || !$contact->access('view', $account)) {
      return AccessResult::forbidden();
    }

    // Must have contact field on request.
    if (!$req->hasField('field_contact')) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowed();
  }

}
