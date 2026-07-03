<?php

declare(strict_types=1);

namespace Drupal\wo_clock\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;

/**
 * The clock-in/out block for the current WO page.
 *
 * Detects the work_order from the route and the current user, then renders one
 * of three states (A: not clocked in, B: clocked in here, C: clocked in
 * elsewhere) via the shared _wo_clock_build_render() helper. Placeable as a
 * block; the module also renders the same UI inline on the WO page (see
 * wo_clock_work_order_view()).
 *
 * @Block(
 *   id = "wo_clock_block",
 *   admin_label = @Translation("WO Clock In/Out"),
 *   category = @Translation("Work Orders")
 * )
 */
final class WoClockBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $wo_id = $this->resolveWoId();
    if (!$wo_id) {
      return [];
    }
    $render = _wo_clock_build_render($wo_id, \Drupal::currentUser());
    return $render ?? [];
  }

  /**
   * Resolve the work_order id from the current route.
   */
  private function resolveWoId(): int {
    $match = \Drupal::routeMatch();
    foreach (['work_order', 'wo', 'eck_entity'] as $name) {
      $param = $match->getParameter($name);
      if ($param instanceof EntityInterface && $param->getEntityTypeId() === 'work_order') {
        return (int) $param->id();
      }
      if (is_numeric($param)) {
        return (int) $param;
      }
    }
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['user', 'route']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
