<?php

declare(strict_types=1);

namespace Drupal\contract_residential\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

final class ContractThemeNegotiator implements ThemeNegotiatorInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function applies(RouteMatchInterface $route_match): bool {
    // Force admin theme only for these roles.
    $allowed_roles = [
      'administrator',
      'site_admin',
      'administration',
      'site_assistant',
      'supervisor',
    ];

    $user_roles = $this->currentUser->getRoles();

    if (empty(array_intersect($allowed_roles, $user_roles))) {
      return FALSE;
    }

  $route_name = (string) ($route_match->getRouteName() ?? '');
  if ($route_name === '') {
    return FALSE;
  }

  // Contracts entity routes (ECK entity type: contracts).
  if (in_array($route_name, [
    'entity.contracts.canonical',
    'entity.contracts.edit_form',
    'entity.contracts.add_form',
    'entity.contracts.collection',
  ], TRUE)) {
    return TRUE;
  }

  // Any custom contract routes in this module.
  if (str_starts_with($route_name, 'contract_residential.')) {
    return TRUE;
  }

  return FALSE;
}

  public function determineActiveTheme(RouteMatchInterface $route_match): string {
    // Your admin theme machine name.
    return 'brookstone_admin';
  }

}
