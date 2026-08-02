<?php

declare(strict_types=1);

namespace Drupal\bos_property_gallery\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Staff "Gallery" tab on the property page: every photo/video linked to the
 * property (archive + work-order, published + held), rendered as a Colorbox
 * grid, with each item's public-gallery status + an edit link so staff can
 * review and opt photos into the public gallery.
 */
final class PropertyGalleryController extends ControllerBase {

  /** Photo/video media bundles that belong to a property gallery. */
  private const GALLERY_BUNDLES = ['property_photo', 'property_video', 'wo_images', 'wo_videos'];

  /** Roles allowed to see the staff gallery tab. */
  private const STAFF_ROLES = ['teammates', 'supervisor', 'administration', 'site_assistant', 'site_admin', 'administrator'];

  /**
   * Route access: any staff role.
   */
  public function staffAccess(AccountInterface $account): AccessResultInterface {
    $allowed = array_intersect(self::STAFF_ROLES, $account->getRoles());
    return AccessResult::allowedIf(!empty($allowed))->addCacheContexts(['user.roles']);
  }

  /**
   * The staff gallery page.
   */
  public function staffGallery(EntityInterface $properties): array {
    $property = $properties;
    $mids = $this->entityTypeManager()->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_property', $property->id())
      ->condition('bundle', self::GALLERY_BUNDLES, 'IN')
      ->sort('created', 'DESC')
      ->execute();

    $build = [
      '#attached' => ['library' => ['bos_property_gallery/gallery']],
      '#cache' => ['tags' => ['media_list'], 'contexts' => ['user.roles']],
    ];

    if (empty($mids)) {
      $build['empty'] = ['#markup' => '<p class="bos-gallery__empty">' . $this->t('No photos or videos are linked to this property yet.') . '</p>'];
      return $build;
    }

    $viewBuilder = $this->entityTypeManager()->getViewBuilder('media');
    $items = [];
    foreach ($this->entityTypeManager()->getStorage('media')->loadMultiple($mids) as $media) {
      $isPublic = $media->hasField('field_public_ok') && (bool) $media->get('field_public_ok')->value;
      $source = ($media->bundle() === 'property_photo' || $media->bundle() === 'property_video') ? 'Archive' : 'Work Order';
      $date = $media->hasField('field_date_taken') && !$media->get('field_date_taken')->isEmpty()
        ? $media->get('field_date_taken')->value : '';
      $editUrl = $media->toUrl('edit-form', [
        'query' => ['destination' => Url::fromRoute('bos_property_gallery.tab', ['properties' => $property->id()])->toString()],
      ])->toString();

      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bos-gallery__item']],
        'media' => $viewBuilder->view($media, 'gallery'),
        'meta' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['bos-gallery__meta']],
          'badge' => [
            '#markup' => $isPublic
              ? '<span class="bos-gallery__badge bos-gallery__badge--public">Public</span>'
              : '<span class="bos-gallery__badge bos-gallery__badge--held">Not public</span>',
          ],
          'info' => ['#markup' => ' ' . $source . ($date ? ' · ' . $date : '')],
          'edit' => ['#markup' => '<br><a href="' . $editUrl . '">' . $this->t('Edit / toggle public') . '</a>'],
        ],
      ];
    }

    $build['count'] = ['#markup' => '<p>' . $this->formatPlural(count($mids), '1 item', '@count items') . '</p>'];
    $build['grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bos-gallery']],
      'items' => $items,
    ];
    return $build;
  }

}
