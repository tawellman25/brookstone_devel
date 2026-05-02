<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Filter form for the Daily Variance dashboard.
 *
 * GET-submission form so filters live in the URL — bookmarkable and
 * shareable. The controller reads the same query params on render.
 */
final class VarianceDailyFilterForm extends FormBase {

  public function getFormId(): string {
    return 'bos_teammate_operations_variance_daily_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?array $defaults = NULL): array {
    $defaults = $defaults ?? [];

    $form['#attributes']['class'][] = 'bos-variance-filters';
    // GET-submission: form action goes to the variance route; no hidden form ids.
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('bos_teammate_operations.variance_daily')->toString();
    // Suppress Drupal's GET-form noise (form_token / form_id).
    $form['#token'] = FALSE;

    $boundary = $defaults['boundary_date'] ?? '';
    $boundaryDisplay = '';
    if ($boundary) {
      try {
        $boundaryDisplay = (new \DateTime(substr($boundary, 0, 10)))->format('m/d/Y');
      }
      catch (\Throwable $e) {
        $boundaryDisplay = $boundary;
      }
    }
    $startHelp = $boundaryDisplay
      ? $this->t('(recommended: @b or later)', ['@b' => $boundaryDisplay])
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

    $form['department'] = [
      '#type' => 'select',
      '#title' => $this->t('Crew / Department'),
      '#options' => $this->getDepartmentOptions(),
      '#default_value' => $defaults['department'] ?? 'all',
    ];

    $form['teammate'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Teammate'),
      '#description' => $this->t('Optional — start typing a name.'),
      '#selection_settings' => [
        'include_anonymous' => FALSE,
        'filter' => ['role' => ['teammates']],
      ],
      '#default_value' => !empty($defaults['teammate']) ? \Drupal::entityTypeManager()->getStorage('user')->load($defaults['teammate']) : NULL,
      '#size' => 30,
    ];

    $form['show_inactive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show inactive (no activity in range)'),
      '#default_value' => !empty($defaults['show_inactive']),
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
    // No-op: GET form. Submission lands at the same controller with query params.
  }

  /**
   * Build the department dropdown options from the crew_types ECK entity.
   */
  private function getDepartmentOptions(): array {
    $options = ['all' => $this->t('— All Departments —')];
    try {
      $crews = \Drupal::entityTypeManager()->getStorage('crew_types')->loadByProperties([]);
      foreach ($crews as $crew) {
        $options[(string) $crew->id()] = $crew->label();
      }
    }
    catch (\Throwable $e) {
      // crew_types may not exist on all environments — fall back gracefully.
    }
    asort($options);
    return $options;
  }

}
