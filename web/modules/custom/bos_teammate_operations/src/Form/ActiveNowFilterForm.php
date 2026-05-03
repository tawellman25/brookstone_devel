<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filter form for the Active Now view.
 *
 * GET-submission so filters live in the URL — bookmarkable, shareable,
 * and the controller reads the same query params on render.
 */
final class ActiveNowFilterForm extends FormBase {

  public function getFormId(): string {
    return 'bos_teammate_operations_active_now_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?array $defaults = NULL): array {
    $defaults = $defaults ?? [];

    $form['#attributes']['class'][] = 'bos-active-now-filters';
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('bos_teammate_operations.active_now')->toString();
    $form['#token'] = FALSE;

    $form['department'] = [
      '#type' => 'select',
      '#title' => $this->t('Department'),
      '#options' => $this->getDepartmentOptions(),
      '#default_value' => $defaults['department'] ?? 'all',
    ];

    $form['group_by_department'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Group by department'),
      '#default_value' => !empty($defaults['group_by_department']),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // GET submission — no-op.
  }

  /** Same crew_types-driven dropdown the variance daily page uses. */
  private function getDepartmentOptions(): array {
    $options = ['all' => $this->t('— All Departments —')];
    try {
      $crews = \Drupal::entityTypeManager()->getStorage('crew_types')->loadByProperties([]);
      foreach ($crews as $crew) {
        $options[(string) $crew->id()] = $crew->label();
      }
    }
    catch (\Throwable $e) {
      // crew_types absent — fall back gracefully.
    }
    asort($options);
    return $options;
  }

}
