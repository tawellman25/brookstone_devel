<?php

declare(strict_types=1);

namespace Drupal\bos_hoa\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Public HOA / common-area showcase page.
 *
 * Renders ONLY public-safe fields (name, neighborhood, description, service
 * timeframe, public photos) for an `hoa` bundle property — never the internal
 * fields the bundle inherits from the property superset (contacts, gate code,
 * WO notes, etc.). Residential properties are unaffected (this route is hoa-only).
 */
final class HoaPublicController extends ControllerBase {

  /**
   * Access: published hoa-bundle entities are public; everything else denied
   * (admins with the entity view perm may always see it).
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account) {
    $entity = $route_match->getParameter('properties');
    if (!$entity instanceof EntityInterface || $entity->bundle() !== 'hoa') {
      return AccessResult::forbidden()->addCacheContexts(['route']);
    }
    // Published (or non-publishable) HOAs are public; unpublished are staff-only.
    $published = !($entity instanceof \Drupal\Core\Entity\EntityPublishedInterface) || $entity->isPublished();
    if ($published) {
      return AccessResult::allowed()->addCacheableDependency($entity);
    }
    return AccessResult::allowedIfHasPermission($account, 'view properties entities')
      ->addCacheableDependency($entity);
  }

  /**
   * Page title = HOA name.
   */
  public function title(EntityInterface $properties): string {
    return $properties->label() ?: 'HOA Common Area';
  }

  /**
   * Render the public showcase.
   */
  public function view(EntityInterface $properties): array {
    $get = function (string $field) use ($properties) {
      return ($properties->hasField($field) && !$properties->get($field)->isEmpty())
        ? $properties->get($field) : NULL;
    };

    // Public description (formatted text).
    $desc = NULL;
    if ($d = $get('field_hoa_public_desc')) {
      $desc = [
        '#type' => 'processed_text',
        '#text' => $d->value,
        '#format' => $d->format ?: 'basic_html',
      ];
    }

    // Service timeframe (US dates; open-ended while active).
    $fmt = function ($item) {
      if (!$item) { return NULL; }
      try {
        return (new DrupalDateTime($item->value))->format('m/d/Y');
      }
      catch (\Throwable $e) { return NULL; }
    };
    $start = $fmt($get('field_service_start'));
    $end = $fmt($get('field_service_end'));
    $timeframe = NULL;
    if ($start) {
      $timeframe = $end ? "$start – $end" : "Since $start";
    }

    // Public photo gallery (reuse the public media view, keyed on this HOA id).
    $gallery = views_embed_view('property_photos_public', 'default', (string) $properties->id());

    $build = [
      '#theme' => 'bos_hoa_public',
      '#name' => $properties->label(),
      '#neighborhood' => ($n = $get('field_neighborhood')) ? $n->value : NULL,
      '#description' => $desc,
      '#timeframe' => $timeframe,
      '#gallery' => $gallery,
      '#phone' => (string) (\Drupal::config('bos_service_request.settings')->get('office_phone') ?? ''),
      '#attached' => ['library' => ['bos_hoa/public_page']],
      '#cache' => [
        'tags' => $properties->getCacheTags(),
        'contexts' => ['url'],
      ],
    ];
    return $build;
  }

}
