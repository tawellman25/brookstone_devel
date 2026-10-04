<?php

declare(strict_types=1);

namespace Drupal\bos_edge_cache\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manual edge purge.
 *
 * A form rather than a plain route because a form carries CSRF protection for
 * free, and because purging the edge is a deliberate act that deserves a
 * confirmation step rather than a URL somebody can be linked into.
 *
 * It sets the same flag `hook_cache_flush()` does rather than emitting the
 * header itself. This submission is an authenticated POST, so its own response
 * is not edge-cacheable — which means the subscriber emits the purge on this
 * very response. The flag is not a delay; it is the mechanism that guarantees
 * the header can only ever ride on a response the edge will not store.
 */
final class EdgePurgeForm extends FormBase {

  // Declared, not promoted: FormBase already declares its own properties and
  // DependencySerializationTrait re-injects on __wakeup, which it cannot do for
  // constructor-promoted ones.
  protected $stateService;

  public function __construct(StateInterface $state) {
    $this->stateService = $state;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('state'));
  }

  public function getFormId(): string {
    return 'bos_edge_cache_purge_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $pending = (bool) $this->stateService->get('bos_edge_cache.purge_pending');

    $form['explain'] = [
      '#markup' => '<p>' . $this->t('Clears everything LiteSpeed is holding for anonymous visitors. Drupal\'s own caches are not touched — this only drops the copies the edge is serving without PHP.') . '</p>'
      . '<p>' . $this->t('A cache rebuild already queues a purge automatically, so this is for the case where the edge is serving something stale and you would rather not wait.') . '</p>',
    ];

    if ($pending) {
      $form['pending'] = [
        '#markup' => '<p><em>' . $this->t('A purge is already queued and will be sent with the next page that is not edge-cacheable.') . '</em></p>',
      ];
    }

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge the edge cache'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->stateService->set('bos_edge_cache.purge_pending', TRUE);
    $this->messenger()->addStatus($this->t('Edge cache purge sent. Anonymous visitors will get freshly rendered pages until the edge fills again.'));
  }

}
