<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Moves core's contact form off /contact so the rebuilt page can own it.
 * The core form stays reachable at /contact/feedback during cutover, so nothing
 * arriving during the switch falls on the floor.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('contact.site_page')) {
      $route->setPath('/contact/feedback');
    }
  }

}
