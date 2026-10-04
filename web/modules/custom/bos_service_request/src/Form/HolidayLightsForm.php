<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * /holiday-lights — the 2026 holiday lighting campaign landing page.
 *
 * Mirrors /winterize: a route whose _form IS the page, a dedicated marketing
 * template, an inline confirmation rather than a redirect, and the same
 * dataLayer conversion event mechanism.
 *
 * ⚠ IT CREATES NO NEW ECK BUNDLE. A holiday quote is a service_request:
 * general_inquiry with field_topic = 'quote' — the bundle and the topic value
 * both already exist and are already wired into the office queue, the office
 * notification and the admin card view. The thing that distinguishes a holiday
 * lead from any other quote is field_campaign, which is exactly how BOS already
 * attributes fb26 / goog26 / react26. Cloning a 28-field bundle to carry one
 * extra meaning would have been the expensive wrong answer.
 *
 * Unlike WinterizeForm this does NOT do property matching or eligibility: a
 * holiday enquiry is a lead, not an intake against an existing service year, and
 * §6.0's no-property-disclosure rule is satisfied trivially because no property
 * lookup happens at all.
 */
final class HolidayLightsForm extends FormBase {

  public const BUNDLE = 'general_inquiry';

  /** A holiday lead IS a quote request; the topic value already exists. */
  public const TOPIC = 'quote';

  /** Default attribution when no ?c= is supplied. */
  public const CAMPAIGN = 'holiday26';

  protected $etm;
  protected $flood;
  protected $srLogger;
  protected $estimator;

  public static function create(ContainerInterface $container): static {
    $i = new static();
    $i->etm = $container->get('entity_type.manager');
    $i->flood = $container->get('flood');
    $i->srLogger = $container->get('logger.channel.bos_service_request');
    $i->estimator = $container->get('bos_service_request.holiday_estimator');
    return $i;
  }

  public function getFormId(): string {
    return 'bos_holiday_lights_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Inline confirmation, the /winterize pattern — the visitor stays on the
    // landing page and the conversion event fires on this same rebuild.
    if ($done = $form_state->get('holiday_done')) {
      $form['confirmation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bo-confirmation']],
        'msg' => ['#markup' => $done],
      ];
      return $form;
    }

    // bo-form-card is the class that carries ALL the form styling — input
    // widths, labels, focus, actions. Without it every field falls back to
    // browser defaults and the two-column rows overflow their container, which
    // is exactly what happened. bo-grid-2 is the existing two-up row; it
    // already collapses to one column on mobile.
    $form['#attributes']['class'][] = 'holiday-form';
    $form['#attributes']['class'][] = 'bo-form-card';

    $form['row_name'] = ['#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']]];
    $form['row_name']['first_name'] = ['#type' => 'textfield', '#title' => $this->t('First name'), '#required' => TRUE];
    $form['row_name']['last_name'] = ['#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE];

    $form['row_contact'] = ['#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']]];
    $form['row_contact']['phone'] = ['#type' => 'tel', '#title' => $this->t('Phone'), '#required' => TRUE];
    $form['row_contact']['email'] = ['#type' => 'email', '#title' => $this->t('Email')];

    $form['address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Property address'),
      '#required' => TRUE,
      '#description' => $this->t('Street address and town.'),
    ];

    // --- estimate builder -------------------------------------------------
    // The same calculator the crew uses on site, so a visitor who builds a
    // number here and then gets a walkthrough is not shown a different one.
    // PUBLIC PLAN SET ONLY: Hang & Remove is described in the page copy with an
    // inspection required, never offered as a selectable option — its price
    // depends on the condition of the customer's own lights.
    $plans = $this->estimator->pricedPlans(TRUE);
    $addons = $this->estimator->pricedAddons();

    if ($plans) {
      $form['#attributes']['class'][] = 'hol-est';
      $form['#attached']['library'][] = 'bos_service_request/holiday_estimate';
      $form['#attached']['drupalSettings']['bosHolidayEstimate'] = [
        'plans' => $plans,
        'addons' => array_map(fn($a) => $a['price'], $addons),
        'shrubMin' => (int) ($this->estimator->rate('field_holiday_shrub_min_qty') ?? 0),
        'steepPct' => (float) ($this->estimator->rate('field_holiday_steep_pct') ?? 0),
        'tallAdder' => (float) ($this->estimator->rate('field_holiday_tall_adder') ?? 0),
      ];

      $options = [];
      foreach ($plans as $key => $plan) {
        $options[$key] = sprintf('%s · $%s/ft', $plan['label'], number_format($plan['rate'], 2));
      }
      $form['plan'] = [
        '#type' => 'select',
        '#title' => $this->t('Plan'),
        '#options' => $options,
        '#default_value' => isset($options['two_year']) ? 'two_year' : array_key_first($options),
      ];

      $form['feet'] = [
        '#type' => 'number',
        '#title' => $this->t('Roofline feet'),
        '#min' => 0,
        '#step' => 1,
        '#required' => FALSE,
        '#description' => $this->t('A rough number is fine — single-story homes run about 120–180 ft and two-story 160–240 ft. We measure it on site either way.'),
      ];

      $form['est_trees'] = ['#type' => 'details', '#title' => $this->t('Trees you want lit'), '#open' => FALSE];
      $form['est_items'] = ['#type' => 'details', '#title' => $this->t('Wreaths, columns, windows, borders'), '#open' => FALSE];
      foreach ($addons as $key => $info) {
        $group = str_starts_with($key, 'dec_') || str_starts_with($key, 'eg_') ? 'est_trees' : 'est_items';
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

      $form['est_cond'] = ['#type' => 'details', '#title' => $this->t('About the roof'), '#open' => FALSE];
      $form['est_cond']['steep'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Steep roof'),
      ];
      $form['est_cond']['tall'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Anything higher than about 25 feet'),
      ];

      $form['live'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['hol-est__live'], 'id' => 'hol-est-live', 'aria-live' => 'polite'],
        '#markup' => '<p class="hol-est__empty">' . $this->t('Add your roofline feet for an estimate.') . '</p>',
      ];

      $form['est_caveat'] = [
        '#markup' => '<p class="hol-est__closing">'
        . $this->t('This is an estimate, not a quote. Your real price comes off a measurement — we confirm it on site, usually within a dollar or two per foot.')
        . '</p>',
      ];
    }

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Anything you want lit, or anything we should know'),
      '#rows' => 3,
      '#required' => FALSE,
    ];

    $form['captcha'] = ['#type' => 'captcha', '#captcha_type' => 'recaptcha/reCAPTCHA'];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Get My Quote'),
      // winterize-submit is the styled submit inside .bo-form-card; bo-btn is
      // the hero CTA and sits differently in .form-actions.
      '#attributes' => ['class' => ['winterize-submit']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->getRequest()->getClientIp();
    if (!$this->flood->isAllowed('bos_service_request.holiday_ip', 5, 3600, $ip)) {
      $form_state->setErrorByName('', $this->t('Too many requests from this connection. Please call the office at 970-835-9661.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $v = $form_state->getValues();
    $name = trim($v['first_name'] . ' ' . $v['last_name']);

    // Roofline feet rides in the message rather than as a new field: it is one
    // optional free-text hint the crew re-measures anyway, and adding an ECK
    // field for it would put a column on every general_inquiry record.
    $parts = [];

    // Recompute from the price book rather than trusting the browser, then
    // store the itemisation with the lead so whoever does the walkthrough
    // arrives knowing what the customer built. Spec section 11.
    $plans = $this->estimator->pricedPlans(TRUE);
    $feet = (float) ($v['feet'] ?? 0);
    if ($plans && $feet > 0) {
      $quantities = [];
      foreach (array_keys($this->estimator->pricedAddons()) as $key) {
        $quantities[$key] = (int) ($v[$key] ?? 0);
      }
      $planKey = isset($plans[$v['plan'] ?? '']) ? (string) $v['plan'] : (string) array_key_first($plans);
      $result = $this->estimator->price($planKey, $feet, $quantities, !empty($v['steep']), !empty($v['tall']));
      $parts[] = "Estimate the customer built on the website:\n" . $this->estimator->asText($result);
    }
    elseif ($feet > 0) {
      $parts[] = 'Approximate roofline feet: ' . $feet;
    }

    if (trim((string) ($v['message'] ?? '')) !== '') {
      $parts[] = trim((string) $v['message']);
    }

    $req = $this->getRequest();
    $campaign = preg_replace('/[^a-z0-9-]/', '', mb_strtolower((string) $req->query->get('c', ''))) ?: self::CAMPAIGN;

    $values = [
      'type' => self::BUNDLE,
      'uid' => 0,
      'field_topic' => self::TOPIC,
      'field_submitted_name' => $name,
      'field_submitted_phone' => $v['phone'],
      'field_submitted_email' => $v['email'],
      'field_submitted_address' => $v['address'],
      'field_customer_notes' => implode("\n\n", $parts),
      'field_campaign' => $campaign,
      // field_source is the CHANNEL, not the service — the service is already
      // carried by field_topic + field_campaign. Resolved through the shared
      // mapper so a ?c=goog26 holiday lead files as Google Ads exactly like a
      // winterize one, and an unmapped code cannot produce a value the
      // constrained list rejects.
      'field_source' => \Drupal\bos_service_request\CampaignSource::forCode($campaign),
      'field_review_flags' => 'quote_lead',
    ];
    $status = \Drupal::service('bos_service_request.status_resolver')->tid('New');
    if ($status) {
      $values['field_request_status'] = $status;
    }

    $record = $this->etm->getStorage('service_request')->create($values);
    $record->save();
    $ref = (string) ($record->get('field_public_ref')->value ?? $record->id());

    $this->flood->register('bos_service_request.holiday_ip', 3600, $req->getClientIp());
    $this->srLogger->info('Holiday lighting request @ref → @id (campaign @c).',
      ['@ref' => $ref, '@id' => $record->id(), '@c' => $campaign]);

    $form_state->set('holiday_done',
      '<h2>Thanks — we have your request.</h2><p>We will call to set up a walkthrough and measure your roofline. '
      . 'Your reference is <strong>' . htmlspecialchars($ref, ENT_QUOTES) . '</strong>. '
      . 'If you need us sooner, call <a href="tel:+19708359661">970-835-9661</a>.</p>');
    $form_state->setRebuild(TRUE);
  }

}
