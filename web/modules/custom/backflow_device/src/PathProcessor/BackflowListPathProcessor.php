<?php

declare(strict_types=1);

namespace Drupal\backflow_device\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maps the property-path "/{property-alias}/backflow" URL to the device list.
 *
 * Backflow devices are aliased under their property as
 * "/{property-alias}/backflow/bf-000027". Stripping the BF number to
 * "/{property-alias}/backflow" lands on that property's device list (the
 * backflow_property_devices_eva:page_1 view at /properties/{id}/backflow), so a
 * field user can see every device at the property.
 *
 * There is no stored alias for the intermediate "/backflow" segment — this
 * resolves it at request time from the property's current alias, so it stays
 * correct if the property alias later changes, with no per-property alias to
 * maintain.
 *
 * - Inbound: rewrite "/{property-alias}/backflow" → "/properties/{id}/backflow".
 * - Outbound: render "/properties/{id}/backflow" as "/{property-alias}/backflow"
 *   so the URL is self-consistent and the redirect module's route normalizer
 *   does NOT 301 the pretty URL to the raw system path (it serves 200 instead).
 *
 * Only the bare ".../backflow" tail is handled; device aliases that end in
 * ".../backflow/bf-xxxxxx" do not match and are left to the alias system.
 */
final class BackflowListPathProcessor implements InboundPathProcessorInterface, OutboundPathProcessorInterface {

  public function __construct(
    private readonly AliasManagerInterface $aliasManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    if (!preg_match('#^(/.+)/backflow$#', $path, $m)) {
      return $path;
    }
    $system = $this->aliasManager->getPathByAlias($m[1]);
    if (preg_match('#^/properties/(\d+)$#', $system, $pm)) {
      return '/properties/' . $pm[1] . '/backflow';
    }
    return $path;
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], ?Request $request = NULL, ?BubbleableMetadata $bubbleable_metadata = NULL) {
    if (preg_match('#^/properties/(\d+)/backflow$#', $path, $m)) {
      $property_system = '/properties/' . $m[1];
      $alias = $this->aliasManager->getAliasByPath($property_system);
      if ($alias !== $property_system) {
        // Property has a pretty alias — render the list under it.
        return $alias . '/backflow';
      }
    }
    return $path;
  }

}
