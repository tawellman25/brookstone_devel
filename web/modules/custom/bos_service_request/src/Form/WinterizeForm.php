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
      $paras = '';
      foreach (preg_split('/\n\n+/', $done) as $p) {
        $p = trim($p);
        if ($p !== '') {
          $paras .= '<p>' . Html::escape($p) . '</p>';
        }
      }
      $form['confirmation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['winterize-confirmation']],
        'msg' => ['#markup' => '<h2>Thank you</h2>' . $paras],
      ];
      return $form;
    }

    // Open/closed gate — a static informational page, not a 404.
    if (!$this->signupOpen($cfg)) {
      $closed = !empty($cfg['closed_notice'])
        ? (string) $cfg['closed_notice']
        : strtr('Winterization signup for @year is closed. Call the office at @phone and we\'ll tell you what\'s still possible.', ['@year' => $year, '@phone' => $phone]);
      $form['closed'] = ['#markup' => '<h2>Winterization signup</h2><p>' . Html::escape($closed) . '</p>'];
      return $form;
    }

    // The form IS the card (page--winterize.html.twig owns the header, hero,
    // "what happens next", accordions and footer). Built to the approved mockup:
    // a 2-column field grid inside the .bo-form-card. Keeps working unstyled if
    // the stylesheet fails (the form is the critical path).
    $form['#attributes']['class'][] = 'winterize-form';
    $form['#attributes']['class'][] = 'bo-form-card';

    // Row 1 — First name + Last name. (P3.3 #2: surname is what the matcher keys on.)
    $form['row_name'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']],
      'submitted_first_name' => [
        '#type' => 'textfield', '#title' => $this->t('First name'), '#required' => TRUE, '#maxlength' => 255,
        // No autofocus — the page should land on the hero, not jump to the field.
      ],
      'submitted_name' => [
        '#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE, '#maxlength' => 255,
      ],
    ];
    // Row 2 — Phone + Email.
    $form['row_contact'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']],
      'submitted_phone' => ['#type' => 'tel', '#title' => $this->t('Phone'), '#required' => TRUE, '#maxlength' => 32],
      'submitted_email' => ['#type' => 'email', '#title' => $this->t('Email'), '#maxlength' => 255],
    ];
    // Row 3 — Service address (2fr) + ZIP (1fr).
    $form['row_addr'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2-1']],
      'submitted_address' => ['#type' => 'textfield', '#title' => $this->t('Service address'), '#required' => TRUE, '#maxlength' => 255],
      'submitted_zip' => ['#type' => 'textfield', '#title' => $this->t('ZIP'), '#required' => TRUE, '#maxlength' => 10],
    ];
    // Row 4 — water supply (P1.3; "Not sure" first-class default).
    $form['row_supply'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']],
      'water_supply' => [
        '#type' => 'select', '#title' => $this->t('What supplies water to your system?'),
        '#options' => [
          'unsure' => $this->t('Not sure'),
          'city' => $this->t('City / town water'),
          'ditch' => $this->t('Irrigation ditch or canal'),
          'well' => $this->t('Private well'),
        ],
        '#default_value' => 'unsure',
      ],
    ];
    $form['access_notes'] = ['#type' => 'textarea', '#title' => $this->t('Gate & access notes'), '#rows' => 2];
    // P3.3 #5 — the two near-duplicate textareas merged into one.
    $form['changed'] = ['#type' => 'textarea', '#title' => $this->t('Anything we should know about your sprinkler system?'), '#rows' => 2];

    // P3.3 #1 / P1.2a — specific-date DEMAND SIGNAL only (orange-ruled block,
    // set apart from the opt-ins). NO fee calculated/quoted/stored/reserved.
    $form['specific_date_block'] = [
      '#type' => 'container', '#attributes' => ['class' => ['winterize-specific-date']],
      'wants_specific_date' => [
        '#type' => 'checkbox',
        '#title' => $this->t('I need a specific date — please call me'),
        '#description' => $this->t('Additional fees may apply. Routes are set by area, so a fixed date pulls us off route.'),
      ],
    ];
    // P1.2 — two cross-sell opt-ins (recorded intent only; no auto-creation).
    $form['optin_block'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-optin']],
      'wants_recurring' => ['#type' => 'checkbox', '#title' => $this->t('Add me to the automatic winterizing list each fall')],
      'wants_startup' => ['#type' => 'checkbox', '#title' => $this->t('Contact me in the spring about turning my system back on')],
    ];

    // P0.5 — freeze-damage disclaimer with the warning glyph (Markup::create so
    // the inline SVG survives — #markup's Xss filter would strip it).
    if (!empty($cfg['freeze_disclaimer'])) {
      $svg = '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#B0703A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg>';
      $form['freeze_disclaimer'] = [
        '#markup' => \Drupal\Core\Render\Markup::create('<div class="winterize-disclaimer">' . $svg . '<p>' . Html::escape((string) $cfg['freeze_disclaimer']) . '</p></div>'),
      ];
    }

    // captcha — reCAPTCHA (P3.3 #3; keys configured 2026-08-23).
    $form['captcha'] = ['#type' => 'captcha', '#captcha_type' => 'recaptcha/reCAPTCHA'];

    $form['actions'] = ['#type' => 'actions'];
    // P3.3 #4 — "Get on the list" matches the postcard.
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Get on the list'), '#attributes' => ['class' => ['winterize-submit']]];
    $form['actions']['limit'] = ['#markup' => '<span class="bo-form__limit">' . Html::escape((string) $this->t('Space is limited — routes fill through October.')) . '</span>'];

    return $form;
  }

  /**
   * P1.3 — TRUE when the matched property has water-source records but none of
   * the submitted supply type. Read-only; never writes property_ss_sources.
   * Path: properties ← property_sprinkler_info.field_property → field_systems →
   * property_sprinkler_system ← property_ss_sources.field_property_ss_system.
   * "unsure"/empty never mismatches; no source records → nothing to disagree.
   */
  private function waterSupplyMismatch(int $propertyId, string $submitted): bool {
    $map = ['city' => 'domestic_source', 'ditch' => 'dirty_water_source', 'well' => 'well_water_source'];
    if (!isset($map[$submitted])) {
      return FALSE;
    }
    $infoStorage = $this->entityTypeManager->getStorage('property_sprinkler_info');
    $infoIds = $infoStorage->getQuery()->accessCheck(FALSE)->condition('field_property', $propertyId)->execute();
    if (!$infoIds) {
      return FALSE;
    }
    $systemIds = [];
    foreach ($infoStorage->loadMultiple($infoIds) as $info) {
      foreach ($info->get('field_systems') as $ref) {
        if ($ref->target_id) {
          $systemIds[] = $ref->target_id;
        }
      }
    }
    if (!$systemIds) {
      return FALSE;
    }
    $srcStorage = $this->entityTypeManager->getStorage('property_ss_sources');
    $srcIds = $srcStorage->getQuery()->accessCheck(FALSE)->condition('field_property_ss_system', $systemIds, 'IN')->execute();
    if (!$srcIds) {
      return FALSE;
    }
    $bundles = [];
    foreach ($srcStorage->loadMultiple($srcIds) as $s) {
      $bundles[$s->bundle()] = TRUE;
    }
    return !isset($bundles[$map[$submitted]]);
  }

  /**
   * Load services term 369 (the winterizing service). Public copy source.
   */
  private function serviceTerm(): ?object {
    $cfg = $this->bundleConfig();
    $tid = (int) ($cfg['service_term_id'] ?? 0);
    return $tid ? $this->entityTypeManager->getStorage('taxonomy_term')->load($tid) : NULL;
  }

  /**
   * Build the public body into JS-free <details> accordions (split by <h3>).
   * Works with JavaScript disabled; renders via full_html so the tags survive.
   */
  private function bodyAccordions(string $html): array {
    $parts = preg_split('#<h3>(.*?)</h3>#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $blocks = '';
    $intro = trim($parts[0] ?? '');
    if ($intro !== '') {
      $blocks .= '<div class="winterize-detail-intro">' . $intro . '</div>';
    }
    for ($i = 1; $i < count($parts); $i += 2) {
      $heading = trim($parts[$i] ?? '');
      $content = trim($parts[$i + 1] ?? '');
      if ($heading === '' && $content === '') {
        continue;
      }
      $blocks .= '<details class="winterize-detail-section"><summary>' . $heading . '</summary>' . $content . '</details>';
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['winterize-detail']],
      '#weight' => 70,
      'content' => ['#type' => 'processed_text', '#text' => $blocks, '#format' => 'full_html'],
    ];
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
    $firstName = $get('submitted_first_name');
    // Surname — the matcher keys on this; kept separate from the stored full name.
    $name = $get('submitted_name');
    $fullName = trim($firstName . ' ' . $name);
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

    // P1.3 — supply disagreement on a matched property. The raw answer is stored
    // regardless; this only raises a review flag and changes NOTHING on
    // property_ss_sources (those records stay authoritative).
    if ($propertyId && $this->waterSupplyMismatch($propertyId, (string) $form_state->getValue('water_supply'))) {
      $flags[] = 'supply_mismatch';
      $flags = array_values(array_unique($flags));
    }

    // P3.3 #1 — specific-date demand signal (flag only; no fee anywhere).
    $wantsSpecificDate = (bool) $form_state->getValue('wants_specific_date');
    if ($wantsSpecificDate) {
      $flags[] = 'wants_specific_date';
      $flags = array_values(array_unique($flags));
    }

    // Customer notes (verbatim record of what a stranger typed). Water supply is
    // now a structured field (field_water_supply), not free text.
    $customerNotes = $this->composeNotes([
      'About the sprinkler system' => $get('changed'),
      'Notes' => $get('notes'),
    ]);
    $officeNotes = $campaignNote;

    // P1.3 water supply (list_string). P0.5 notice version = hash of the exact
    // freeze disclaimer this submitter was shown.
    $waterSupply = (string) $form_state->getValue('water_supply');
    $disclaimerShown = (string) ($cfg['freeze_disclaimer'] ?? '');

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
      'field_submitted_name' => $fullName,
      'field_submitted_address' => $address,
      'field_submitted_zip' => $zip,
      'field_submitted_phone' => $phoneIn,
      'field_submitted_email' => $email,
      'field_access_notes' => $get('access_notes'),
      'field_customer_notes' => $customerNotes,
      'field_office_notes' => $officeNotes,
      'field_wants_recurring' => (bool) $form_state->getValue('wants_recurring'),
      'field_wants_startup' => (bool) $form_state->getValue('wants_startup'),
      'field_wants_specific_date' => $wantsSpecificDate,
      'field_review_flags' => implode("\n", $flags),
      'field_notice_version' => $disclaimerShown !== '' ? substr(hash('sha256', $disclaimerShown), 0, 64) : '',
    ];
    if ($propertyId) {
      $values['field_property'] = $propertyId;
    }
    if (in_array($waterSupply, ['city', 'ditch', 'well', 'unsure'], TRUE)) {
      $values['field_water_supply'] = $waterSupply;
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
      $message = strtr("Good news — you're already on our winterization list. No additional signup is necessary.\n\nWe'll be starting in early October and will contact you with the week we plan to be in your area. If anything about your system has changed, or you need a specific date, call us at @phone.", ['@phone' => $phone]);
    }
    elseif ($elig && $elig->outcome === EligibilityResult::DUPLICATE && $corroborated) {
      $message = strtr("We've already received your winterization request. Reference: @ref. No additional signup is necessary — we'll be in touch with your service week.", ['@ref' => $ref]);
    }
    else {
      // Neutral received message — identical for matched, ambiguous, unmatched,
      // flagged, and non-corroborated covered/duplicate (enumeration control).
      $message = strtr("You're on the list. Reference: @ref.\n\nWe winterize by geographic route through October and will contact you with the week we plan to be in your area. If a hard freeze is possible before then, cover your backflow or pump.\n\nQuestions, or need a specific date? Call us at @phone.\n\nThank you for choosing Brookstone Outdoors.", ['@ref' => $ref, '@phone' => $phone]);
    }

    // P3.3 #1 — one extra line when a specific date was requested. Still promises
    // no date, window or availability, and quotes no fee.
    if ($wantsSpecificDate) {
      $message .= "\n\nYou asked about a specific date — we'll call you about that.";
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
    // The Bear Creek landing route forces its campaign even without ?c=.
    if ($raw === '' && \Drupal::routeMatch()->getRouteName() === 'bos_service_request.bear_creek') {
      $raw = 'bearcreek26';
    }
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
