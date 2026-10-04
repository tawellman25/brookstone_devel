<?php

declare(strict_types=1);

namespace Drupal\bos_jsonapi_guard\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Makes every JSON:API route require an authenticated user.
 *
 * JSON:API publishes every content entity type automatically and serialises
 * each field that passes FIELD access — which in Drupal defaults to ALLOW. It
 * does not consult view modes, so curating a display protects the HTML page and
 * nothing else. Measured on live 2026-10-04: anonymous requests returned the
 * whole trip-fee schedule (field_trip_fee, field_check_up_route_day for every
 * ZIP) and HOA street addresses, GPS and field_cod_customer, plus a 623-type map
 * of the data model. Security scanners had already pulled it.
 *
 * Entity access was doing its job — customer properties, contacts, work orders
 * and ownership records all returned zero rows. What leaked was the handful of
 * entity types deliberately opened so a PUBLIC PAGE could render: the geo pages,
 * the HOA showcase, the material catalogue, the backflow QR tags. Opening an
 * entity type for a page opens every field on it to every other reader.
 *
 * This gate is deliberately at the ROUTE, not at entity access: JSON:API lives
 * entirely under /jsonapi (16,322 routes, one of which sits elsewhere — hence
 * matching on the route NAME, not the path), so requiring authentication here
 * closes the anonymous hole without touching the public pages, which are
 * ordinary page routes and keep working unchanged.
 *
 * It is NOT the whole fix. This stops strangers; it says nothing about which
 * teammate may see which field. Field-access guards on the exposed entity types
 * are still needed — material.module is the working pattern — and they are what
 * makes a teammate mobile app safe to build, because the server decides what a
 * given role may see rather than every client re-implementing the rules.
 */
final class JsonApiRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection as $name => $route) {
      // By NAME: jsonapi.resource_list and the per-resource routes all carry the
      // prefix, including the one route whose path is not under /jsonapi.
      if (strpos($name, 'jsonapi.') !== 0) {
        continue;
      }
      $route->setRequirement('_user_is_logged_in', 'TRUE');
    }
  }

}
