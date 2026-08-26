<?php

declare(strict_types=1);

namespace Drupal\bos_handbook_ack\Plugin\Block;

use Drupal\bos_handbook_ack\Form\HandbookAcknowledgmentForm;
use Drupal\bos_handbook_ack\Service\HandbookAckService;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "I acknowledge the Team Handbook" form, for placement on the ack page.
 *
 * @Block(
 *   id = "handbook_acknowledgment_block",
 *   admin_label = @Translation("Handbook Acknowledgment Form")
 * )
 */
final class HandbookAcknowledgmentBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly FormBuilderInterface $formBuilder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('form_builder'));
  }

  public function build(): array {
    return [
      'form' => $this->formBuilder->getForm(HandbookAcknowledgmentForm::class),
      '#cache' => ['max-age' => 0],
    ];
  }

  protected function blockAccess(AccountInterface $account): AccessResult {
    // Staff only (all staff must acknowledge).
    return AccessResult::allowedIf((bool) array_intersect($account->getRoles(), HandbookAckService::STAFF_ROLES))
      ->addCacheContexts(['user.roles']);
  }

}
