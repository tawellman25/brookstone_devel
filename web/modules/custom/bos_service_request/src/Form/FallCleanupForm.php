<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\bos_service_request\Service\ServiceRequestStatusResolver;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public Fall Cleanup intake form.
 *
 * Same architecture as WinterizeForm: Form API, reCAPTCHA, flood, server-side
 * property match, ?c= campaign. Creates a service_request:fall_cleanup. The three
 * cross-sell checkboxes are honored downstream:
 *  - Winterize my sprinklers → also creates a linked service_request:
 *    sprinkler_winterizing so the person lands in the winterization queue.
 *  - Spring landscape project → also creates an estimate_request (design-build
 *    lead) + a review flag.
 *  - Snow removal contract → a review flag (office follows up; no snow intake
 *    bundle exists).
 *
 * Injected services are DECLARED (not constructor-promoted) — a captcha rebuild
 * re-serializes the form and DependencySerializationTrait must re-inject them.
 */
final class FallCleanupForm extends FormBase {

  protected $entityTypeManager;
  protected $configFactory;
  protected $matcher;
  protected $statusResolver;
  protected $normalizer;
  protected $flood;
  protected $requestStack;
  protected $time;
  protected $srLogger;

  public const BUNDLE = 'fall_cleanup';

  public static function create(ContainerInterface $container): static {
    $i = new static();
    $i->entityTypeManager = $container->get('entity_type.manager');
    $i->configFactory = $container->get('config.factory');
    $i->matcher = $container->get('bos_service_request.property_matcher');
    $i->statusResolver = $container->get('bos_service_request.status_resolver');
    $i->normalizer = $container->get('bos_wo_intake.property_normalizer');
    $i->flood = $container->get('flood');
    $i->requestStack = $container->get('request_stack');
    $i->time = $container->get('datetime.time');
    $i->srLogger = $container->get('logger.channel.bos_service_request');
    return $i;
  }

  public function getFormId(): string {
    return 'bos_service_request_fall_cleanup';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $cfg = $this->bundleConfig();
    $phone = (string) $this->configFactory->get('bos_service_request.settings')->get('office_phone');

    // Confirmation state (set by submit).
    if ($done = $form_state->get('fc_done')) {
      $paras = '';
      foreach (preg_split('/\n\n+/', $done) as $p) {
        $p = trim($p);
        if ($p !== '') {
          $paras .= '<p>' . Html::escape($p) . '</p>';
        }
      }
      $form['confirmation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['fc-confirmation']],
        'msg' => ['#markup' => '<h2>Thank you</h2>' . $paras],
      ];
      return $form;
    }

    // Open/closed gate.
    if (!$this->signupOpen($cfg)) {
      $closed = !empty($cfg['closed_notice'])
        ? (string) $cfg['closed_notice']
        : strtr('Fall cleanup signup is closed. Call the office at @phone.', ['@phone' => $phone]);
      $form['closed'] = ['#markup' => '<h2>Fall cleanup signup</h2><p>' . Html::escape($closed) . '</p>'];
      return $form;
    }

    $form['#attributes']['class'][] = 'fc-form';
    $form['#attributes']['class'][] = 'bo-form-card';

    $form['row_name'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']],
      'submitted_first_name' => ['#type' => 'textfield', '#title' => $this->t('First name'), '#required' => TRUE, '#maxlength' => 255],
      'submitted_name' => ['#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE, '#maxlength' => 255],
    ];
    $form['row_contact'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']],
      'submitted_phone' => ['#type' => 'tel', '#title' => $this->t('Phone'), '#required' => TRUE, '#maxlength' => 32],
      'submitted_email' => ['#type' => 'email', '#title' => $this->t('Email'), '#required' => TRUE, '#maxlength' => 255],
    ];
    $form['row_addr'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2-1']],
      'submitted_address' => ['#type' => 'textfield', '#title' => $this->t('Service address'), '#required' => TRUE, '#maxlength' => 255],
      'submitted_zip' => ['#type' => 'textfield', '#title' => $this->t('ZIP'), '#required' => TRUE, '#maxlength' => 10],
    ];

    $form['fc_needs'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('What do you need?'),
      '#options' => $this->allowedValues('field_fc_needs'),
    ];
    $form['fc_tree_count'] = [
      '#type' => 'select',
      '#title' => $this->t('About how many mature trees drop on the property?'),
      '#options' => ['' => $this->t('- Select -')] + $this->allowedValues('field_fc_tree_count'),
    ];

    $form['access_notes'] = [
      '#type' => 'textarea', '#title' => $this->t('Gate & access notes'),
      '#description' => $this->t('dogs, locked gates, alley access'), '#rows' => 2,
    ];
    $form['notes'] = ['#type' => 'textarea', '#title' => $this->t('Anything else we should know?'), '#rows' => 2];

    $form['optins'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-optin']],
      'wants_winterize' => ['#type' => 'checkbox', '#title' => $this->t('Winterize my sprinklers while you are here')],
      'wants_snow' => ['#type' => 'checkbox', '#title' => $this->t('Contact me about a snow removal contract')],
      'wants_landscape' => ['#type' => 'checkbox', '#title' => $this->t('Contact me in the spring about a landscape project')],
    ];

    // Campaign — hidden, populated on page load from ?c=, survives validation.
    $existing = (string) $form_state->getValue('campaign', '');
    if ($existing === '') {
      $existing = (string) $this->requestStack->getCurrentRequest()->query->get('c', '');
    }
    $form['campaign'] = ['#type' => 'hidden', '#default_value' => $existing];

    $form['captcha'] = ['#type' => 'captcha', '#captcha_type' => 'recaptcha/reCAPTCHA'];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit', '#value' => $this->t('Request your fall cleanup'),
      '#attributes' => ['class' => ['fc-submit', 'bo-btn']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Per-IP flood (same mechanism family as winterize).
    $ip = $this->requestStack->getCurrentRequest()->getClientIp();
    $floodCfg = $this->configFactory->get('bos_service_request.settings')->get('flood') ?? [];
    if (!$this->flood->isAllowed('bos_service_request.fall_cleanup_ip', (int) ($floodCfg['per_ip_hour'] ?? 5), 3600, $ip)) {
      $form_state->setErrorByName('', $this->t('Too many requests from this connection. Please call the office at 970-835-9661.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $cfg = $this->bundleConfig();
    $serviceTermId = (int) ($cfg['service_term_id'] ?? 0);
    $serviceYear = (int) ($cfg['service_year'] ?? (int) date('Y'));
    $phone = (string) $this->configFactory->get('bos_service_request.settings')->get('office_phone');
    $req = $this->requestStack->getCurrentRequest();

    $get = fn(string $k) => mb_substr(trim((string) $form_state->getValue($k)), 0, 1000);
    $firstName = $get('submitted_first_name');
    $lastName = $get('submitted_name');
    $fullName = trim($firstName . ' ' . $lastName);
    $address = $get('submitted_address');
    $zip = $get('submitted_zip');
    $phoneIn = $get('submitted_phone');
    $email = $get('submitted_email');

    [$campaign, $source, $campaignNote] = $this->resolveCampaign($form_state);

    $match = $this->matcher->match($lastName, $address, $zip, $phoneIn, $email);
    $flags = $match['flags'] ?? [];
    $propertyId = ($match['status'] === 'matched') ? $match['property_id'] : NULL;
    $statusName = $propertyId ? ServiceRequestStatusResolver::NEW : ServiceRequestStatusResolver::NEEDS_REVIEW;

    $wantsWinterize = (bool) $form_state->getValue('wants_winterize');
    $wantsSnow = (bool) $form_state->getValue('wants_snow');
    $wantsLandscape = (bool) $form_state->getValue('wants_landscape');
    if ($wantsWinterize) {
      $flags[] = 'winterize_requested';
    }
    if ($wantsSnow) {
      $flags[] = 'snow_contract_requested';
    }
    if ($wantsLandscape) {
      $flags[] = 'landscape_lead';
    }
    $flags = array_values(array_unique($flags));

    // Selected needs (checkboxes → array of keys).
    $needs = array_values(array_filter((array) $form_state->getValue('fc_needs')));
    $treeCount = (string) $form_state->getValue('fc_tree_count');

    $customerNotes = $this->composeNotes(['Notes' => $get('notes')]);

    $storage = $this->entityTypeManager->getStorage('service_request');
    $values = [
      'type' => self::BUNDLE,
      'uid' => 0,
      'field_service' => $serviceTermId ?: NULL,
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
      'field_office_notes' => $campaignNote,
      'field_fc_needs' => $needs,
      'field_fc_tree_count' => $treeCount !== '' ? $treeCount : NULL,
      'field_fc_wants_winterize' => $wantsWinterize,
      'field_fc_wants_snow' => $wantsSnow,
      'field_fc_wants_landscape' => $wantsLandscape,
      'field_review_flags' => implode("\n", $flags),
    ];
    if ($propertyId) {
      $values['field_property'] = $propertyId;
    }
    if ($match['status'] === 'ambiguous' && !empty($match['candidates'])) {
      $values['field_match_candidates'] = json_encode($match['candidates']);
    }
    $request = $storage->create($values);
    $request->save();
    $ref = (string) $request->get('field_public_ref')->value;

    // Cross-sell downstream records.
    $winterizeRef = NULL;
    if ($wantsWinterize) {
      $winterizeRef = $this->createLinkedWinterize($request, $fullName, $address, $zip, $phoneIn, $email, $propertyId, $campaign, $source);
    }
    if ($wantsLandscape) {
      $this->createDesignBuildLead($request, $fullName, $address, $phoneIn, $email, $propertyId);
    }
    if ($winterizeRef || $wantsLandscape) {
      // Persist the links written by the helpers.
      $request->save();
    }

    // Flood register on success.
    $this->flood->register('bos_service_request.fall_cleanup_ip', 3600, $req->getClientIp());

    $this->srLogger->info('Public fall-cleanup submission @ref → request @id (status @s, campaign @c, winterize @w, snow @sn, landscape @l).', [
      '@ref' => $ref, '@id' => $request->id(), '@s' => $statusName, '@c' => $campaign,
      '@w' => $wantsWinterize ? 'y' : 'n', '@sn' => $wantsSnow ? 'y' : 'n', '@l' => $wantsLandscape ? 'y' : 'n',
    ]);

    $message = strtr("You're on the list. Reference: @ref.\n\nWe start once leaf drop is mostly finished and work by location — we'll contact you with the week we plan to be in your area. Need a specific date? Call the office early, before routes are set, at @phone.", ['@ref' => $ref, '@phone' => $phone]);
    if ($wantsWinterize) {
      $message .= "\n\nWe'll also line up your sprinkler winterization for the same week.";
    }
    if ($wantsSnow) {
      $message .= "\n\nWe'll be in touch about a snow removal contract.";
    }
    if ($wantsLandscape) {
      $message .= "\n\nAnd we'll reach out in the spring about your landscape project.";
    }
    $message .= "\n\nThank you for choosing Brookstone Outdoors.";

    $form_state->set('fc_done', $message);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Create a linked winterize request so the customer lands in that queue.
   */
  private function createLinkedWinterize($fcRequest, string $name, string $address, string $zip, string $phone, string $email, ?int $propertyId, string $campaign, string $source): ?string {
    $wCfg = $this->configFactory->get('bos_service_request.settings')->get('bundles.sprinkler_winterizing') ?? [];
    $fcRef = (string) $fcRequest->get('field_public_ref')->value;
    $values = [
      'type' => 'sprinkler_winterizing',
      'uid' => 0,
      'field_service' => (int) ($wCfg['service_term_id'] ?? 0) ?: NULL,
      'field_service_year' => (int) ($wCfg['service_year'] ?? (int) date('Y')),
      'field_request_status' => $this->statusResolver->tid(ServiceRequestStatusResolver::NEEDS_REVIEW),
      'field_source' => $source,
      'field_campaign' => $campaign,
      'field_submitted_name' => $name,
      'field_submitted_address' => $address,
      'field_submitted_zip' => $zip,
      'field_submitted_phone' => $phone,
      'field_submitted_email' => $email,
      'field_office_notes' => 'Winterization requested via Fall Cleanup request ' . $fcRef . '. Verify and schedule.',
      'field_review_flags' => 'from_fall_cleanup',
    ];
    if ($propertyId) {
      $values['field_property'] = $propertyId;
    }
    $w = $this->entityTypeManager->getStorage('service_request')->create($values);
    $w->save();
    if ($fcRequest->hasField('field_fc_linked_winterize')) {
      $fcRequest->set('field_fc_linked_winterize', $w->id());
    }
    return (string) $w->get('field_public_ref')->value;
  }

  /**
   * Create a design-build (landscape) lead in the estimate pipeline.
   */
  private function createDesignBuildLead($fcRequest, string $name, string $address, string $phone, string $email, ?int $propertyId): void {
    $fcRef = (string) $fcRequest->get('field_public_ref')->value;
    $statusTid = $this->termIdByName('estimate_request_status', 'New - Gathering Info');
    $serviceTid = $this->termIdByName('services', 'Landscaping');
    $values = [
      'type' => 'standard',
      'uid' => 0,
      'field_priority' => 'normal',
      'field_requestor_name' => $name,
      'field_requestor_address' => $address,
      'field_requestor_phone' => $phone,
      'field_requestor_email' => $email,
      'field_client_requested' => [
        'value' => 'Spring landscape project — lead captured from Fall Cleanup request ' . $fcRef . '. Contact the customer in the spring.',
        'format' => 'basic_html',
      ],
    ];
    if ($statusTid) {
      $values['field_status'] = $statusTid;
    }
    if ($serviceTid) {
      $values['field_service'] = $serviceTid;
    }
    if ($propertyId) {
      $values['field_property'] = $propertyId;
    }
    $lead = $this->entityTypeManager->getStorage('estimate_request')->create($values);
    $lead->save();
    if ($fcRequest->hasField('field_fc_linked_estimate')) {
      $fcRequest->set('field_fc_linked_estimate', $lead->id());
    }
  }

  // ── helpers ────────────────────────────────────────────────────────────────

  private function bundleConfig(): array {
    return $this->configFactory->get('bos_service_request.settings')->get('bundles.' . self::BUNDLE) ?? [];
  }

  private function allowedValues(string $fieldName): array {
    $storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('service_request', $fieldName);
    $vals = $storage ? ($storage->getSetting('allowed_values') ?? []) : [];
    $out = [];
    foreach ($vals as $k => $v) {
      $out[$k] = $this->t('@v', ['@v' => $v]);
    }
    return $out;
  }

  private function termIdByName(string $vid, string $name): ?int {
    $t = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
    return $t ? (int) reset($t)->id() : NULL;
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

  private function resolveCampaign(FormStateInterface $form_state): array {
    $allow = $this->configFactory->get('bos_service_request.settings')->get('campaigns') ?? [];
    $raw = mb_substr(trim((string) $form_state->getValue('campaign', '')), 0, 64);
    if ($raw === '') {
      return ['website', 'website', ''];
    }
    if (in_array($raw, $allow, TRUE)) {
      $sourceVal = ($raw === 'website') ? 'website' : 'other';
      return [$raw, $sourceVal, ''];
    }
    return ['unknown', 'other', 'Unrecognized campaign code: ' . Html::escape($raw)];
  }

  private function signupOpen(array $cfg): bool {
    if (empty($cfg['signup_open'])) {
      return FALSE;
    }
    $from = !empty($cfg['open_from']) ? $cfg['open_from'] : NULL;
    $until = !empty($cfg['open_until']) ? $cfg['open_until'] : NULL;
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

}
