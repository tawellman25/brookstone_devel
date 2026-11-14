<?php

declare(strict_types=1);

namespace Drupal\bos_scheduling\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Admin theme for the My Check-ups Views tab.
 *
 * The tab lives under /teammates/calendar beside Calendar, My Schedule and
 * Dispatch, which are controller routes flagged _admin_route. A Views page has
 * no such switch, so without this the crew would drop into the public theme on
 * one tab of four.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('view.my_check_ups.page_1')) {
      $route->setOption('_admin_route', TRUE);
    }
  }

}
