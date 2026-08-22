<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\bos_service_request\Service\EligibilityResult;
use Drupal\bos_service_request\Service\ServiceRequestStatusResolver;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public sprinkler-winterization signup (/winterize).
 *
 * INVARIANT (§6.0): the submitter never sees, selects, or influences which
 * property a request binds to. There is NO property element (visible, hidden,
 * disabled, or #access:FALSE). Matching runs server-side in the submit handler,
 * after the form is committed, and its outcome is never revealed. The one
 * message that reflects any BOS state — "already on our list" — fires only when
 * the submitted email/phone corroborates the property's contact.
 *
 * The entity is created programmatically as uid 0 (no anonymous ECK permission).
 *
 * Gotcha #4: properties are protected NON-readonly — captcha forces form-state
 * serialization and readonly-promoted props would be left uninitialized.
 */
final class WinterizeForm extends FormBase {

  protected $entityTypeManager;
  protected $configFactory;
  protected $eligibility;
  protected $matcher;
  protected $statusResolver;
  protected $normalizer;
  protected $flood;
  protected $requestStack;
  protected $time;
  protected $srLogger;

  public const BUNDLE = 'sprinkler_winterizing';

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->configFactory = $container->get('config.factory');
    $instance->eligibility = $container->get('bos_service_request.eligibility');
    $instance->matcher = $container->get('bos_service_request.property_matcher');
    $instance->statusResolver = $container->get('bos_service_request.status_resolver');
    $instance->normalizer = $container->get('bos_wo_intake.property_normalizer');
    $instance->flood = $container->get('flood');
    $instance->requestStack = $container->get('request_stack');
    $instance->time = $container->get('datetime.time');
    $instance->srLogger = $container->get('logger.channel.bos_service_request');
    return $instance;
  }

  public function getFormId(): string {
    return 'bos_winterize_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $cfg = $this->bundleConfig();
    $year = (int) ($cfg['service_year'] ?? (int) date('Y'));
    $phone = (string) $this->configFactory->get('bos_service_request.settings')->get('office_phone');

    // Confirmation state (set by submit; identical structure for every match
    // outcome — one, five, or zero candidates).
    if ($done = $form_state->get('winterize_done')) {
      $form['confirmation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['winterize-confirmation']],
        'msg' => ['#markup' => '<h2>Thank you</h2><p>' . Html::escape($done) . '</p>'],
      ];
      return $form;
    }

    // Open/closed gate — a static informational page, not a 404.
    if (!$this->signupOpen($cfg)) {
      $form['closed'] = [
        '#markup' => '<h2>Winterization signup</h2><p>' . Html::escape(strtr(
          'Winterization signup for @year is closed. Call the office at @phone and we\'ll tell you what\'s still possible.',
          ['@year' => $year, '@phone' => $phone]
        )) . '</p>',
      ];
      return $form;
    }

    $form['#attributes']['class'][] = 'winterize-form';
    $form['intro'] = [
      '#markup' => '<p>' . Html::escape((string) ($cfg['scheduling_notice'] ?? '')) . '</p>',
    ];
    $form['submitted_name'] = [
      '#type' => 'textfield', '#title' => $this->t('Your name'), '#required' => TRUE, '#maxlength' => 255,
      '#description' => $this->t('Last name is fine.'),
      // Start focus in the form so tabbing flows through the fields, not the
      // site header first.
      '#attributes' => ['autofocus' => 'autofocus'],
    ];
    $form['submitted_address'] = [
      '#type' => 'textfield', '#title' => $this->t('Service address'), '#required' => TRUE, '#maxlength' => 255,
    ];
    $form['submitted_zip'] = [
      '#type' => 'textfield', '#title' => $this->t('ZIP code'), '#required' => TRUE, '#maxlength' => 10, '#size' => 12,
    ];
    $form['submitted_phone'] = [
      '#type' => 'tel', '#title' => $this->t('Phone'), '#required' => TRUE, '#maxlength' => 32,
    ];
    $form['submitted_email'] = [
      '#type' => 'email', '#title' => $this->t('Email'), '#maxlength' => 255,
    ];
    $form['water_supply'] = [
      '#type' => 'textarea', '#title' => $this->t('Water supply / where the shutoff is'), '#rows' => 2,
      '#description' => $this->t('Anything that helps us find and shut off your system.'),
    ];
    $form['access_notes'] = [
      '#type' => 'textarea', '#title' => $this->t('Gate & access notes'), '#rows' => 2,
      '#description' => $this->t('Gate codes, dogs, where to park.'),
    ];
    $form['changed'] = [
      '#type' => 'textarea', '#title' => $this->t('Anything changed since last year?'), '#rows' => 2,
    ];
    $form['notes'] = [
      '#type' => 'textarea', '#title' => $this->t('Anything else we should know'), '#rows' => 2,
    ];
    $form['wants_recurring'] = [
      '#type' => 'checkbox', '#title' => $this->t('Add me to the automatic winterizing list each fall.'),
    ];

    // captcha — site default challenge (recaptcha is enabled).
    $form['captcha'] = ['#type' => 'captcha', '#captcha_type' => 'default'];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Request winterization')];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Flood: per IP per hour, and per normalized address per service year.
    $cfg = $this->bundleConfig();
    $floodCfg = $this->configFactory->get('bos_service_request.settings')->get('flood') ?? [];
    $ip = $this->requestStack->getCurrentRequest()->getClientIp();
    if (!$this->flood->isAllowed('bos_service_request.winterize_ip', (int) ($floodCfg['per_ip_hour'] ?? 5), 3600, $ip)) {
      $form_state->setErrorByName('submitted_name', $this->t('Too many requests from your connection. Please try again later or call the office.'));
      return;
    }
    $addrKey = $this->addressFloodKey($form_state, $cfg);
    if (!$this->flood->isAllowed('bos_service_request.winterize_addr', (int) ($floodCfg['per_address_year'] ?? 2), $this->serviceYearSeconds($cfg), $addrKey)) {
      $form_state->setErrorByName('submitted_address', $this->t('We already have a request for this address. If something changed, call the office.'));
      return;
    }
    // Stash the normalized address so the submit handler is not rebuilt-empty
    // (getValues() can be empty at build time on a rebuild — gotcha #5).
    $form_state->set('addr_flood_key', $addrKey);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $cfg = $this->bundleConfig();
    $serviceTermId = (int) ($cfg['service_term_id'] ?? 0);
    $serviceYear = (int) ($cfg['service_year'] ?? (int) date('Y'));
    $phone = (string) $this->configFactory->get('bos_service_request.settings')->get('office_phone');
    $disclose = (bool) ($cfg['disclose_existing_coverage'] ?? TRUE);

    // Defense-in-depth: ignore ANY attempt to inject a property identifier
    // (§6.0 / §9). There is no property element; matching is server-side only.
    $req = $this->requestStack->getCurrentRequest();
    foreach (['property_id', 'field_property'] as $k) {
      if ($req->request->has($k) || $req->query->has($k)) {
        $this->srLogger->warning('Ignored injected @k on public /winterize submission from @ip.', [
          '@k' => $k, '@ip' => $req->getClientIp(),
        ]);
      }
    }

    $get = fn(string $key) => $this->clean((string) $form_state->getValue($key));
    $name = $get('submitted_name');
    $address = $get('submitted_address');
    $zip = $get('submitted_zip');
    $phoneIn = $get('submitted_phone');
    $email = $get('submitted_email');

    // Campaign — untrusted 'c' query param, normalized against the allowlist.
    [$campaign, $source, $campaignNote] = $this->resolveCampaign($req);

    // Server-side property match (invisible to the submitter).
    $match = $this->matcher->match($name, $address, $zip, $phoneIn, $email);
    $flags = $match['flags'];
    $propertyId = ($match['status'] === 'matched') ? $match['property_id'] : NULL;

    // Eligibility (only meaningful on a confident single match).
    $elig = $propertyId ? $this->eligibility->evaluate($propertyId, $serviceTermId, $serviceYear) : NULL;

    // Internal status + coverage links.
    [$statusName, $existingWoId, $existingContractId, $extraFlags] = $this->classify($match['status'], $elig);
    $flags = array_values(array_unique(array_merge($flags, $extraFlags)));

    // Customer notes (verbatim record of what a stranger typed).
    $customerNotes = $this->composeNotes([
      'Water supply / shutoff' => $get('water_supply'),
      'Changed since last year' => $get('changed'),
      'Notes' => $get('notes'),
    ]);
    $officeNotes = $campaignNote;

    // Create the record (uid 0). field_property set ONLY on a confident match.
    $storage = $this->entityTypeManager->getStorage('service_request');
    $values = [
      'type' => self::BUNDLE,
      'uid' => 0,
      'field_service' => $serviceTermId,
      'field_service_year' => $serviceYear,
      'field_request_status' => $this->statusResolver->tid($statusName),
      'field_source' => $source,
      'field_campaign' => $campaign,
      'field_submitted_name' => $name,
      'field_submitted_address' => $address,
      'field_submitted_zip' => $zip,
      'field_submitted_phone' => $phoneIn,
      'field_submitted_email' => $email,
      'field_access_notes' => $get('access_notes'),
      'field_customer_notes' => $customerNotes,
      'field_office_notes' => $officeNotes,
      'field_wants_recurring' => (bool) $form_state->getValue('wants_recurring'),
      'field_review_flags' => implode("\n", $flags),
    ];
    if ($propertyId) {
      $values['field_property'] = $propertyId;
    }
    if ($match['status'] === 'ambiguous' && !empty($match['candidates'])) {
      $values['field_match_candidates'] = json_encode($match['candidates']);
    }
    if ($existingWoId) {
      $values['field_existing_work_order'] = $existingWoId;
    }
    if ($existingContractId) {
      $values['field_existing_contract'] = $existingContractId;
    }
    $request = $storage->create($values);
    $request->save();
    $ref = (string) $request->get('field_public_ref')->value;

    // Register flood on a successful submission.
    $floodCfg = $this->configFactory->get('bos_service_request.settings')->get('flood') ?? [];
    $this->flood->register('bos_service_request.winterize_ip', 3600, $req->getClientIp());
    if ($addrKey = $form_state->get('addr_flood_key')) {
      $this->flood->register('bos_service_request.winterize_addr', $this->serviceYearSeconds($cfg), $addrKey);
    }

    $this->srLogger->info('Public winterize submission @ref → request @id (status @s, source @src, campaign @c).', [
      '@ref' => $ref, '@id' => $request->id(), '@s' => $statusName, '@src' => $source, '@c' => $campaign,
    ]);

    // Confirmation copy (§7 verbatim). State is disclosed ONLY when the submitter
    // corroborates the property's contact — a street address alone never unlocks it.
    $corroborated = $propertyId && $disclose && $this->matcher->contactCorroborates($propertyId, $phoneIn, $email);
    if ($elig && $elig->outcome === EligibilityResult::ALREADY_COVERED && $corroborated) {
      $message = strtr("You're already on our winterization list. No additional signup is necessary. If anything about your system has changed, call the office at @phone.", ['@phone' => $phone]);
    }
    elseif ($elig && $elig->outcome === EligibilityResult::DUPLICATE && $corroborated) {
      $message = strtr("We've already received your winterization request. Reference: @ref. No additional signup is necessary.", ['@ref' => $ref]);
    }
    else {
      // Neutral received message — identical for matched, ambiguous, unmatched,
      // flagged, and non-corroborated covered/duplicate.
      $message = strtr("Your sprinkler winterization request has been received. Reference: @ref. Brookstone schedules winterizations by geographic route through October. We'll contact you when your service window is assigned, or if we need more information.", ['@ref' => $ref]);
    }

    $form_state->set('winterize_done', $message);
    $form_state->setRebuild(TRUE);
  }

  // ── helpers ────────────────────────────────────────────────────────────────

  private function bundleConfig(): array {
    return $this->configFactory->get('bos_service_request.settings')->get('bundles.' . self::BUNDLE) ?? [];
  }

  private function signupOpen(array $cfg): bool {
    // The gate is office-editable on the Business Settings page (config_pages
    // business_setting). Those fields are authoritative when present; otherwise
    // fall back to the module config (bundles.sprinkler_winterizing.*).
    $open = !empty($cfg['signup_open']);
    $from = !empty($cfg['open_from']) ? $cfg['open_from'] : NULL;
    $until = !empty($cfg['open_until']) ? $cfg['open_until'] : NULL;
    $bs = \Drupal::service('config_pages.loader')->load('business_setting');
    if ($bs && $bs->hasField('field_winterize_signup_open')) {
      $open = (bool) $bs->get('field_winterize_signup_open')->value;
      $from = $bs->get('field_winterize_open_from')->isEmpty() ? NULL : $bs->get('field_winterize_open_from')->value;
      $until = $bs->get('field_winterize_open_until')->isEmpty() ? NULL : $bs->get('field_winterize_open_until')->value;
    }
    if (!$open) {
      return FALSE;
    }
    $tz = new \DateTimeZone(date_default_timezone_get());
    $now = (new DrupalDateTime('now', $tz))->getTimestamp();
    if ($from && $now < (new DrupalDateTime($from . ' 00:00:00', $tz))->getTimestamp()) {
      return FALSE;
    }
    if ($until && $now > (new DrupalDateTime($until . ' 23:59:59', $tz))->getTimestamp()) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Normalize + length-cap submitted text (mb_substr — gotcha #13).
   */
  private function clean(string $s): string {
    return mb_substr(trim($s), 0, 1000);
  }

  private function composeNotes(array $labelled): string {
    $parts = [];
    foreach ($labelled as $label => $value) {
      if ($value !== '') {
        $parts[] = $label . ': ' . $value;
      }
    }
    return implode("\n", $parts);
  }

  /**
   * Resolve the 'c' query param: [campaign, source, officeNote].
   */
  private function resolveCampaign($request): array {
    $allow = $this->configFactory->get('bos_service_request.settings')->get('campaigns') ?? [];
    $raw = (string) $request->query->get('c', '');
    if ($raw === '') {
      return ['website', 'website', ''];
    }
    if (in_array($raw, $allow, TRUE)) {
      $source = ($raw === 'website') ? 'website' : 'postcard_qr';
      return [$raw, $source, ''];
    }
    // Unknown — store 'unknown', keep the raw (capped, escaped) in office notes.
    $note = 'Unrecognized campaign code: ' . Html::escape(mb_substr($raw, 0, 64));
    return ['unknown', 'postcard_qr', $note];
  }

  /**
   * @return array{0:string,1:int|null,2:int|null,3:string[]}
   *   [statusName, existingWorkOrderId, existingContractId, extraFlags]
   */
  private function classify(string $matchStatus, ?EligibilityResult $elig): array {
    // Unmatched / ambiguous → always office review.
    if ($matchStatus === 'unmatched' || $matchStatus === 'ambiguous') {
      return [ServiceRequestStatusResolver::NEEDS_REVIEW, NULL, NULL, []];
    }
    // Matched — status follows eligibility.
    if ($elig) {
      switch ($elig->outcome) {
        case EligibilityResult::ELIGIBLE:
          // Accepted as New, but carry any soft-signal flags (e.g.
          // contract_completed_for_year — P0.2) into field_review_flags.
          return [ServiceRequestStatusResolver::NEW, NULL, NULL, $elig->flags];

        case EligibilityResult::ALREADY_COVERED:
          return [ServiceRequestStatusResolver::ALREADY_COVERED, $elig->existingWorkOrderId, $elig->existingContractId, []];

        case EligibilityResult::DUPLICATE:
          return [ServiceRequestStatusResolver::DUPLICATE, NULL, NULL, []];

        case EligibilityResult::NOT_ELIGIBLE:
          // Recorded + flagged, Needs Review, never auto-convertible.
          return [ServiceRequestStatusResolver::NEEDS_REVIEW, NULL, NULL, $elig->flags];
      }
    }
    return [ServiceRequestStatusResolver::NEEDS_REVIEW, NULL, NULL, []];
  }

  private function addressFloodKey(FormStateInterface $form_state, array $cfg): string {
    $addr = $this->normalizer->normalizeStreet((string) $form_state->getValue('submitted_address'));
    $zip = preg_replace('/\D+/', '', (string) $form_state->getValue('submitted_zip'));
    $year = (int) ($cfg['service_year'] ?? (int) date('Y'));
    return hash('sha256', $addr . '|' . $zip . '|' . $year);
  }

  private function serviceYearSeconds(array $cfg): int {
    // Roughly one service year; used as the flood window for per-address caps.
    return 60 * 60 * 24 * 366;
  }

}
