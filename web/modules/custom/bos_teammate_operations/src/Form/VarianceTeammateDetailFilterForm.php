<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filter form for the per-teammate variance detail page.
 *
 * GET-submission so the URL is bookmarkable. Same pattern as the
 * rollup's filter form.
 */
final class VarianceTeammateDetailFilterForm extends FormBase {

  public function getFormId(): string {
    return 'bos_teammate_operations_variance_teammate_detail_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?array $defaults = NULL): array {
    $defaults = $defaults ?? [];
    $uid = (int) ($defaults['uid'] ?? 0);

    $form['#attributes']['class'][] = 'bos-variance-filters';
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute(
      'bos_teammate_operations.variance_teammate_detail',
      ['user' => $uid]
    )->toString();
    $form['#token'] = FALSE;

    $boundary = $defaults['boundary_date'] ?? '';
    $startHelp = $boundary
      ? $this->t('(recommended: @b or later)', ['@b' => $boundary])
      : '';

    $form['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start date'),
      '#default_value' => $defaults['start_date'] ?? '',
      '#description' => $startHelp,
      '#size' => 14,
    ];

    $form['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#default_value' => $defaults['end_date'] ?? '',
      '#size' => 14,
    ];

    $form['show_anomalies_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Days with anomalies only'),
      '#default_value' => !empty($defaults['show_anomalies_only']),
    ];

    $form['show_activity_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Days with activity only'),
      '#default_value' => !empty($defaults['show_activity_only']),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply Filters'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No-op; GET-submission lands at the detail controller.
  }

}
