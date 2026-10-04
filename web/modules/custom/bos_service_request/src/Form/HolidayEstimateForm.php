<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\bos_service_request\Holiday\HolidayEstimator;
use Drupal\bos_service_request\CampaignSource;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * On-site holiday lighting estimate builder, for a teammate at the curb.
 *
 * The counterpart to the public estimator: same prices, same calculator, but
 * built for somebody standing in a driveway with a laser measure — plan,
 * measured feet, counters for everything else, a running total, and one button
 * to leave the estimate in BOS so the office sees what was quoted and by whom.
 *
 * Deliberately NOT a quote. The sheet is explicit that the real price comes off
 * a measurement, and this screen says so; it does not create a contract, set a
 * price on a work order, or touch billing.
 *
 * The total is computed twice on purpose. JavaScript recalculates as the
 * teammate types, because a round-trip per keystroke on a phone in a truck is
 * unusable — and the SERVER recomputes from HolidayEstimator on submit, so what
 * gets stored is the server's figure and never a number posted by a browser.
 */
final class HolidayEstimateForm extends FormBase {

  // Declared, not constructor-promoted: FormBase uses
  // DependencySerializationTrait, which cannot re-inject promoted properties on
  // __wakeup if this form is ever serialized across a rebuild.
  protected $estimator;
  protected $etm;
  protected $who;

  public function __construct(HolidayEstimator $estimator, EntityTypeManagerInterface $etm, AccountInterface $who) {
    $this->estimator = $estimator;
    $this->etm = $etm;
    $this->who = $who;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bos_service_request.holiday_estimator'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'bos_holiday_estimate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $addons = $this->estimator->pricedAddons();

    // Plans the office has priced. NULL = all three: the teammate is standing
    // in front of the lights and can judge Hang & Remove on the spot, which is
    // exactly why the public form does not offer it.
    $planOptions = [];
    $planRates = [];
    foreach ($this->estimator->pricedPlans(NULL) as $key => $plan) {
      $planOptions[$key] = sprintf('%s · $%s/ft', $plan['label'], number_format($plan['rate'], 2));
      $planRates[$key] = [
        'rate' => $plan['rate'],
        'full_service' => $plan['full_service'],
        'minimum' => $plan['minimum'],
      ];
    }

    if (!$planOptions) {
      $form['none'] = [
        '#markup' => '<p>' . $this->t('No holiday lighting rates are set yet. Business Settings → Holiday Lighting.') . '</p>',
      ];
      return $form;
    }

    $form['#attributes']['class'][] = 'hol-est';
    $form['#attached']['library'][] = 'bos_service_request/holiday_estimate';
    $form['#attached']['drupalSettings']['bosHolidayEstimate'] = [
      'plans' => $planRates,
      'addons' => array_map(fn($a) => $a['price'], $addons),
      'shrubMin' => (int) ($this->estimator->rate('field_holiday_shrub_min_qty') ?? 0),
      'steepPct' => (float) ($this->estimator->rate('field_holiday_steep_pct') ?? 0),
      'tallAdder' => (float) ($this->estimator->rate('field_holiday_tall_adder') ?? 0),
    ];

    $form['intro'] = [
      '#markup' => '<p class="hol-est__intro">' . $this->t('Measure each run of roofline and eaves, then add 15% for corners and valleys before you enter the total. Everything else is counted, not measured.') . '</p>',
    ];

    $form['plan'] = [
      '#type' => 'select',
      '#title' => $this->t('Plan'),
      '#options' => $planOptions,
      // Two-year leads, per the sheet: quote it first, every time.
      '#default_value' => isset($planOptions['two_year']) ? 'two_year' : array_key_first($planOptions),
    ];

    $form['feet'] = [
      '#type' => 'number',
      '#title' => $this->t('Roofline feet (measured, +15% added)'),
      '#min' => 0,
      '#step' => 1,
      '#default_value' => 0,
      '#description' => $this->t('Reference only, never quote off it: single-story homes run 120–180 ft, two-story 160–240 ft.'),
    ];

    $form['trees'] = ['#type' => 'details', '#title' => $this->t('Trees'), '#open' => TRUE];
    $form['items'] = ['#type' => 'details', '#title' => $this->t('Wreaths, columns, windows, borders'), '#open' => TRUE];
    foreach ($addons as $key => $info) {
      $group = str_starts_with($key, 'dec_') || str_starts_with($key, 'eg_') ? 'trees' : 'items';
      $form[$group][$key] = [
        '#type' => 'number',
        '#title' => $info['label'] . ' — $' . number_format($info['price'], 0),
        '#description' => $info['help'],
        '#min' => 0,
        '#step' => 1,
        '#default_value' => 0,
        '#attributes' => ['data-hol-addon' => $key],
      ];
    }

    $form['conditions'] = ['#type' => 'details', '#title' => $this->t('Site conditions'), '#open' => TRUE];
    $form['conditions']['steep'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Steep roof'),
      '#description' => $this->t('Adds @pct% to the roofline portion. Free money — check it on every walkthrough.', [
        '@pct' => rtrim(rtrim(number_format((float) ($this->estimator->rate('field_holiday_steep_pct') ?? 0), 1, '.', ''), '0'), '.'),
      ]),
    ];
    $form['conditions']['tall'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Work above 25 feet'),
      '#description' => $this->t('Flat $@n adder.', ['@n' => number_format((float) ($this->estimator->rate('field_holiday_tall_adder') ?? 0), 0)]),
    ];

    // Where JS writes the running total. The server-rendered result replaces it
    // after a save, so the figure on screen is always one of the two — never a
    // stale mix.
    $form['live'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['hol-est__live'], 'id' => 'hol-est-live', 'aria-live' => 'polite'],
      '#markup' => '<p class="hol-est__empty">' . $this->t('Enter a roofline measurement to start.') . '</p>',
    ];

    $form['who'] = ['#type' => 'details', '#title' => $this->t('Whose house is this?'), '#open' => FALSE,
      '#description' => $this->t('Optional. Fill this in and Save leaves the estimate in BOS for the office.')];
    $form['who']['property'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Property'),
      '#target_type' => 'properties',
      '#description' => $this->t('If they are already a customer, attach it to their property.'),
    ];
    $form['who']['name'] = ['#type' => 'textfield', '#title' => $this->t('Name'), '#maxlength' => 255];
    $form['who']['phone'] = ['#type' => 'tel', '#title' => $this->t('Phone')];
    $form['who']['address'] = ['#type' => 'textfield', '#title' => $this->t('Service address'), '#maxlength' => 255];
    $form['who']['notes'] = ['#type' => 'textarea', '#title' => $this->t('Notes from the walkthrough'), '#rows' => 3];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save this estimate'),
      '#button_type' => 'primary',
    ];

    $form['closing'] = [
      '#markup' => '<p class="hol-est__closing">' . $this->t('Estimate only — the real price comes off a measurement. Confirm on site, usually within a dollar or two per foot.') . '</p>',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ((float) $form_state->getValue('feet') <= 0) {
      $form_state->setErrorByName('feet', $this->t('Enter the measured roofline feet.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $v = $form_state->getValues();

    // Server is the authority. Recompute from the price book rather than
    // trusting anything the browser calculated.
    $quantities = [];
    foreach (array_keys($this->estimator->pricedAddons()) as $key) {
      $quantities[$key] = (int) ($v[$key] ?? 0);
    }
    $result = $this->estimator->price(
      (string) $v['plan'],
      (float) $v['feet'],
      $quantities,
      !empty($v['steep']),
      !empty($v['tall']),
    );

    $breakdown = $this->estimator->asText($result);

    // Only create a record when there is somebody to attach it to. A bare
    // calculation is not a lead, and the office does not need a queue full of
    // anonymous arithmetic.
    $identified = trim((string) ($v['name'] ?? '')) !== ''
      || trim((string) ($v['phone'] ?? '')) !== ''
      || trim((string) ($v['address'] ?? '')) !== ''
      || !empty($v['property']);

    if (!$identified) {
      $this->messenger()->addStatus($this->t('Estimate total @t. Nothing was saved — add a name, phone, address or property and save again to leave it for the office.', [
        '@t' => '$' . number_format($result['total'], 2),
      ]));
      $form_state->setRebuild();
      return;
    }

    $notes = array_filter([
      trim((string) ($v['notes'] ?? '')),
      $breakdown,
      $this->t('Built on site by @who.', ['@who' => $this->who->getDisplayName()]),
    ]);

    $values = [
      'type' => 'general_inquiry',
      'field_topic' => 'quote',
      'field_submitted_name' => trim((string) ($v['name'] ?? '')),
      'field_submitted_phone' => trim((string) ($v['phone'] ?? '')),
      'field_submitted_address' => trim((string) ($v['address'] ?? '')),
      'field_customer_notes' => implode("\n\n", $notes),
      'field_campaign' => 'holiday26',
      'field_source' => CampaignSource::forCode('holiday26'),
      'field_review_flags' => 'onsite_estimate',
      'uid' => $this->who->id(),
    ];
    if (!empty($v['property'])) {
      $values['field_property'] = ['target_id' => $v['property']];
    }

    $record = $this->etm->getStorage('service_request')->create($values);
    $record->save();

    $this->messenger()->addStatus($this->t('Estimate @t saved for the office (@ref).', [
      '@t' => '$' . number_format($result['total'], 2),
      '@ref' => $record->get('field_public_ref')->value ?: $record->id(),
    ]));
    $form_state->setRebuild();
  }

}
