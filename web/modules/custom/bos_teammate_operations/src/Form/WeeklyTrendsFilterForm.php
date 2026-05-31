<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filter form for the Weekly Trends view (Phase 2F).
 *
 * GET-submission so filters live in the URL — bookmarkable, shareable.
 * Same shape as ActiveNowFilterForm; adds a sort selector since the
 * default sort (trend worst-first) is one of several useful views.
 */
final class WeeklyTrendsFilterForm extends FormBase {

  public function getFormId(): string {
    return 'bos_teammate_operations_weekly_trends_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?array $defaults = NULL): array {
    $defaults = $defaults ?? [];

    $form['#attributes']['class'][] = 'bos-active-now-filters';
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('bos_teammate_operations.weekly_trends')->toString();
    $form['#token'] = FALSE;

    $form['department'] = [
      '#type' => 'select',
      '#title' => $this->t('Department'),
      '#options' => $this->getDepartmentOptions(),
      '#default_value' => $defaults['department'] ?? 'all',
    ];

    $form['sort'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort by'),
      '#options' => [
        'trend_worst_first' => $this->t('Trend (decliners first)'),
        '4wk_asc'           => $this->t('4-week avg, lowest first'),
        '8wk_asc'           => $this->t('8-week avg, lowest first'),
        'name_asc'          => $this->t('Teammate name (A→Z)'),
      ],
      '#default_value' => $defaults['sort'] ?? 'trend_worst_first',
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

  /** Same crew_types-driven dropdown the other variance pages use. */
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
