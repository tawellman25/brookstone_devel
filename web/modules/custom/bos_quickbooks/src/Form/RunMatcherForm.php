<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks\Form;

use Drupal\bos_quickbooks\CustomerMatcher;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * /admin/office/quickbooks/match — run the name → ListID pass.
 */
final class RunMatcherForm extends ConfirmFormBase {

  protected CustomerMatcher $matcher;

  public static function create(ContainerInterface $container): static {
    $form = new static();
    $form->matcher = $container->get('bos_quickbooks.matcher');
    return $form;
  }

  public function getFormId(): string {
    return 'bos_quickbooks_run_matcher';
  }

  public function getQuestion() {
    return $this->t('Run the customer matching pass?');
  }

  public function getDescription() {
    return $this->t('Every active QuickBooks customer that has no BOS client yet is compared by name (first / last / spouse / company, status suffixes such as EMAIL or NO SERVICES ignored) against the clients in BOS. Exactly one fit → linked and marked “Matched automatically”; two or more → “Ambiguous”; none → “Unmatched”. Rows the office matched by hand or marked Ignored are never touched. Safe to re-run any time.');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('bos_quickbooks.status');
  }

  public function getConfirmText() {
    return $this->t('Run now');
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run — report what would change, write nothing'),
      '#weight' => -5,
    ];
    $form['rematch'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Re-evaluate rows the pass matched earlier as well (never the office\'s own links)'),
      '#weight' => -4,
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $dry = (bool) $form_state->getValue('dry_run');
    $stats = $this->matcher->run($dry, (bool) $form_state->getValue('rematch'));
    $this->messenger()->addStatus($this->t('@prefix @scanned customers scanned: @auto matched automatically, @ambiguous ambiguous, @unmatched unmatched, @duplicate duplicate, @unchanged unchanged.', [
      '@prefix' => $dry ? $this->t('Dry run —') : $this->t('Matching pass done —'),
      '@scanned' => $stats['scanned'],
      '@auto' => $stats['auto'],
      '@ambiguous' => $stats['ambiguous'],
      '@unmatched' => $stats['unmatched'],
      '@duplicate' => $stats['duplicate'],
      '@unchanged' => $stats['unchanged'],
    ]));
    foreach (['ambiguous', 'unmatched', 'duplicate'] as $k) {
      if (!empty($stats['samples'][$k])) {
        $this->messenger()->addWarning($this->t('@k, e.g.: @list', ['@k' => ucfirst($k), '@list' => implode(' · ', $stats['samples'][$k])]));
      }
    }
    $form_state->setRedirectUrl(Url::fromRoute('bos_quickbooks.status'));
  }

}
