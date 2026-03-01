<?php

namespace Drupal\system_readiness\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

final class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    foreach ([
      'entity.system_readiness.collection',
      'entity.system_readiness.add_form',
      'entity.system_readiness.edit_form',
      'entity.system_readiness.delete_form',
      'entity.system_readiness.canonical',
    ] as $name) {
      if ($route = $collection->get($name)) {
        $route->setOption('_admin_route', TRUE);
      }
    }
  }

}
