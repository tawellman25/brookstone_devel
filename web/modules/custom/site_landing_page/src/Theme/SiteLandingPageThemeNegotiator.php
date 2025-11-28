<?php

namespace Drupal\site_landing_page\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Theme negotiator to set the Admin theme for the office_administration bundle of Site Landing Page.
 */
class SiteLandingPageThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    $route_name = $route_match->getRouteName();
    $entity = NULL;

    // Check if route_name is valid before using strpos.
    if ($route_name && strpos($route_name, 'entity.site_landing_page.') === 0) {
      $parameters = $route_match->getParameters();
      if ($parameters->has('site_landing_page')) {
        $entity = $parameters->get('site_landing_page');
      }
      // For add forms, check the bundle parameter.
      elseif ($route_name === 'entity.site_landing_page.add_form') {
        $bundle = $route_match->getParameter('site_landing_page_type');
        return $bundle === 'office_administration';
      }
    }

    // If an entity is found, check its bundle.
    if ($entity && $entity->getEntityTypeId() === 'site_landing_page' && $entity->bundle() === 'office_administration') {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    // Use the configured admin theme, defaulting to 'claro' if not set.
    $admin_theme = \Drupal::config('system.theme')->get('admin') ?: 'claro';
    return $admin_theme;
  }

}