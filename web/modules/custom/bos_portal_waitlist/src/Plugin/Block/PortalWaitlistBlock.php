<?php

namespace Drupal\bos_portal_waitlist\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\bos_portal_waitlist\Form\PortalWaitlistForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Right-column "Coming for customers" panel for the login page.
 *
 * @Block(
 *   id = "bos_portal_waitlist",
 *   admin_label = @Translation("Portal waitlist (login page)")
 * )
 */
class PortalWaitlistBlock extends BlockBase implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface {

  protected FormBuilderInterface $formBuilder;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->formBuilder = $container->get('form_builder');
    return $instance;
  }

  public function build() {
    $bullets = [
      $this->t('Every work order we have completed on your property — the date, the crew, and what was actually done'),
      $this->t('What is scheduled next, and roughly which week we expect to be on your street'),
      $this->t('The property information we keep — irrigation types and zones, controller settings, spray maps, snowplow maps and how many trees and shrubs are planted'),
      $this->t('Your maintenance agreement and what it covers this season'),
    ];
    return [
      '#theme' => 'bos_portal_waitlist_panel',
      '#bullets' => $bullets,
      '#form' => $this->formBuilder->getForm(PortalWaitlistForm::class),
      '#attached' => ['library' => ['bos_portal_waitlist/waitlist']],
      '#cache' => ['contexts' => ['url.query_args:c']],
    ];
  }

  // Public panel — anyone reaching the login page may see it.
  protected function blockAccess(\Drupal\Core\Session\AccountInterface $account) {
    return \Drupal\Core\Access\AccessResult::allowed();
  }

}
