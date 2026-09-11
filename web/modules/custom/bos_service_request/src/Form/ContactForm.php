<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public general-contact form (/contact).
 *
 * Creates a service_request:general_inquiry record (the /winterize intake
 * pattern) so every submission is a real, attributable, reportable lead the
 * office works from — not just an email to a mailbox. The topic field routes it;
 * quote → also opens an estimate_request (design-build pipeline); commercial and
 * problem-reports are flagged + given a distinctive email subject so they are
 * visible, not buried. An email backstop still goes to the monitored mailbox.
 *
 * Props are protected NON-readonly (captcha forces form-state serialization;
 * readonly-promoted props would be left uninitialized on unserialize).
 */
final class ContactForm extends FormBase {

  protected $entityTypeManager;
  protected $configFactory;
  protected $flood;
  protected $requestStack;
  protected $srLogger;
  protected $mailManager;

  public const BUNDLE = 'general_inquiry';
  public const NOTIFY = 'office@brookstoneoutdoors.com';

  public static function create(ContainerInterface $container): static {
    $i = new static();
    $i->entityTypeManager = $container->get('entity_type.manager');
    $i->configFactory = $container->get('config.factory');
    $i->flood = $container->get('flood');
    $i->requestStack = $container->get('request_stack');
    $i->srLogger = $container->get('logger.channel.bos_service_request');
    $i->mailManager = $container->get('plugin.manager.mail');
    return $i;
  }

  public function getFormId(): string {
    return 'bos_contact_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $topicOptions = $this->topicOptions();

    $form['#attributes']['class'][] = 'contact-form';
    $form['#attributes']['class'][] = 'bo-form-card';

    $form['row_name'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']],
      'first_name' => ['#type' => 'textfield', '#title' => $this->t('First name'), '#required' => TRUE, '#maxlength' => 255],
      'last_name' => ['#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE, '#maxlength' => 255],
    ];
    $form['row_contact'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']],
      'phone' => ['#type' => 'tel', '#title' => $this->t('Phone'), '#required' => TRUE, '#maxlength' => 32],
      'email' => ['#type' => 'email', '#title' => $this->t('Email'), '#required' => TRUE, '#maxlength' => 255],
    ];
    $form['topic'] = [
      '#type' => 'select',
      '#title' => $this->t('What is this about?'),
      '#options' => $topicOptions,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
    ];
    $form['property_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Property address'),
      '#maxlength' => 255,
      '#description' => $this->t('If your message is about a specific property.'),
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 5,
    ];
    $form['opt_marketing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send me occasional email about seasonal services and tips'),
      '#default_value' => 0,
    ];

    $form['captcha'] = ['#type' => 'captcha', '#captcha_type' => 'recaptcha/reCAPTCHA'];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send message'),
      '#attributes' => ['class' => ['contact-submit']],
    ];
    $form['actions']['note'] = [
      '#markup' => '<p class="contact-form__note">' . $this->t('We read every message. If your message is about work in progress or something urgent, call instead — the phone is faster and it reaches a person.') . '</p>',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->requestStack->getCurrentRequest()->getClientIp();
    $cfg = $this->configFactory->get('bos_service_request.settings')->get('flood') ?? [];
    if (!$this->flood->isAllowed('bos_service_request.contact_ip', (int) ($cfg['per_ip_hour'] ?? 5), 3600, $ip)) {
      $form_state->setErrorByName('message', $this->t('Too many messages from your connection. Please try again later or call the office at 970-835-9661.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $req = $this->requestStack->getCurrentRequest();
    $get = fn(string $k) => mb_substr(trim((string) $form_state->getValue($k)), 0, 2000);
    $first = $get('first_name');
    $last = $get('last_name');
    $fullName = trim($first . ' ' . $last);
    $phone = $get('phone');
    $email = $get('email');
    $emailNorm = mb_strtolower($email);
    $topic = (string) $form_state->getValue('topic');
    $address = $get('property_address');
    $message = $get('message');
    $wantsMarketing = (bool) $form_state->getValue('opt_marketing');

    [$campaign, $source] = $this->resolveCampaign($req);

    // Topic → review flag + status.
    $flagMap = ['problem' => 'problem_report', 'commercial' => 'commercial_lead', 'quote' => 'quote_lead'];
    $flags = isset($flagMap[$topic]) ? [$flagMap[$topic]] : [];
    // Problem reports + commercial leads warrant a look, not silent queueing.
    $statusName = in_array($topic, ['problem', 'commercial'], TRUE) ? 'Needs Review' : 'New';
    $statusTid = \Drupal::service('bos_service_request.status_resolver')->tid($statusName);

    // 1. The record (system of record) — uid 0.
    $values = [
      'type' => self::BUNDLE,
      'uid' => 0,
      'field_topic' => $topic,
      'field_submitted_name' => $fullName,
      'field_submitted_phone' => $phone,
      'field_submitted_email' => $email,
      'field_submitted_address' => $address,
      'field_customer_notes' => $message,
      'field_campaign' => $campaign,
      'field_source' => $source,
      'field_review_flags' => implode("\n", $flags),
    ];
    if ($statusTid) {
      $values['field_request_status'] = $statusTid;
    }
    $record = $this->entityTypeManager->getStorage('service_request')->create($values);
    $record->save();
    $ref = (string) ($record->get('field_public_ref')->value ?? $record->id());

    $this->flood->register('bos_service_request.contact_ip', 3600, $req->getClientIp());
    $this->srLogger->info('Contact form @ref → request @id (topic @t, campaign @c).', [
      '@ref' => $ref, '@id' => $record->id(), '@t' => $topic, '@c' => $campaign,
    ]);

    // 2. Quote → open a design-build lead in the estimate pipeline (guarded so a
    // failure never breaks the submission — the record + email are guaranteed).
    if ($topic === 'quote') {
      try {
        $statusEr = $this->termIdByName('estimate_request_status', 'New - Gathering Info');
        $er = ['type' => 'standard', 'uid' => 0, 'field_priority' => 'normal',
          'field_requestor_name' => $fullName, 'field_requestor_address' => $address,
          'field_requestor_phone' => $phone, 'field_requestor_email' => $email,
          'field_client_requested' => ['value' => "Website contact-form quote request:\n\n" . $message, 'format' => 'basic_html'],
        ];
        if ($statusEr) {
          $er['field_status'] = $statusEr;
        }
        $lead = $this->entityTypeManager->getStorage('estimate_request')->create($er);
        $lead->save();
        $this->srLogger->info('Contact quote @ref also opened estimate_request @id.', ['@ref' => $ref, '@id' => $lead->id()]);
      }
      catch (\Throwable $e) {
        $this->srLogger->warning('Contact quote @ref: estimate_request not created (@m) — record + email stand.', ['@ref' => $ref, '@m' => $e->getMessage()]);
      }
    }

    // 3. Marketing opt-in → Contact (consent home), match-or-create on email.
    if ($wantsMarketing) {
      $this->applyMarketingOptIn($emailNorm, $first, $last, $req->getClientIp());
    }

    // 4. Email backstop — subject reflects the topic so complaint / commercial
    // stand out. The record is the system of record; the email is the nudge.
    $this->sendBackstop($topic, $this->topicOptions(), $fullName, $phone, $email, $address, $message, $ref, (string) $record->id());

    // 5. Thank-you page (distinct URL → clean conversion fire).
    $form_state->setRedirect('bos_service_request.contact_thankyou', [], ['query' => ['t' => $topic]]);
  }

  // ── helpers ────────────────────────────────────────────────────────────────

  private function topicOptions(): array {
    $vals = \Drupal\field\Entity\FieldStorageConfig::loadByName('service_request', 'field_topic')->getSetting('allowed_values') ?? [];
    return $vals;
  }

  private function resolveCampaign($request): array {
    $allow = $this->configFactory->get('bos_service_request.settings')->get('campaigns') ?? [];
    $raw = (string) $request->query->get('c', '');
    if ($raw === '') {
      return ['website', 'website'];
    }
    if (in_array($raw, $allow, TRUE)) {
      return [$raw, \Drupal\bos_service_request\CampaignSource::forCode($raw)];
    }
    return ['unknown', 'other'];
  }

  private function termIdByName(string $vid, string $name): ?int {
    $t = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
    return $t ? (int) reset($t)->id() : NULL;
  }

  /**
   * Match a Contact on normalized email and set marketing opt-in (never flips an
   * existing opt-out); create a Contact only when none matches. bos_consent_log
   * records the change. email_service is never touched here.
   */
  private function applyMarketingOptIn(string $emailNorm, string $first, string $last, string $ip): void {
    try {
      $storage = $this->entityTypeManager->getStorage('contacts');
      $existing = $storage->getQuery()->accessCheck(FALSE)
        ->condition('type', 'contact')->condition('field_email', $emailNorm)->range(0, 1)->execute();
      $contact = $existing ? $storage->load(reset($existing)) : $storage->create([
        'type' => 'contact', 'title' => trim("$first $last"),
        'field_first_name' => $first, 'field_last_name' => $last, 'field_email' => $emailNorm,
      ]);
      if (!$contact->hasField('field_opt_in_marketing_email')) {
        return;
      }
      $cur = $contact->get('field_opt_in_marketing_email');
      if (!$cur->isEmpty() && (int) $cur->value === 0) {
        // Explicit opt-out — never silently flipped on.
        $this->srLogger->notice('Contact form left an existing marketing opt-out untouched for @e.', ['@e' => $emailNorm]);
        if ($contact->isNew()) { $contact->save(); }
        return;
      }
      $contact->set('field_opt_in_marketing_email', TRUE);
      $contact->set('field_consent_updated', (new DrupalDateTime('now', 'UTC'))->format('Y-m-d\TH:i:s'));
      $contact->set('field_consent_source', 'web_form');
      $contact->_consent_source = 'web_form';
      $contact->_consent_ip = $ip;
      $contact->_consent_note = 'contact form marketing opt-in';
      $contact->save();
    }
    catch (\Throwable $e) {
      $this->srLogger->warning('Contact form marketing opt-in skipped: @m', ['@m' => $e->getMessage()]);
    }
  }

  private function sendBackstop(string $topic, array $topicOptions, string $name, string $phone, string $email, string $address, string $message, string $ref, string $id): void {
    $label = $topicOptions[$topic] ?? $topic;
    $prefix = $topic === 'problem' ? '⚠ PROBLEM' : ($topic === 'commercial' ? 'COMMERCIAL/HOA' : ($topic === 'quote' ? 'QUOTE' : 'Contact'));
    // Fixed canonical host: the request host is unreliable outside a browser
    // request (CLI/cron yield "http://default"), and the office is always on
    // the live domain.
    $base = 'https://brookstoneoutdoors.com';
    $body = [
      "New contact submission ({$label})",
      '',
      "Name:    {$name}",
      "Phone:   {$phone}",
      "Email:   {$email}",
      $address !== '' ? "Address: {$address}" : NULL,
      '',
      'Message:',
      $message,
      '',
      "Reference: {$ref}",
      "Open this request: {$base}/service_request/{$id}",
      "Office queue:      {$base}/admin/office/service-requests",
    ];
    $params = [
      'subject' => "[{$prefix}] Contact form — {$name}",
      'body' => implode("\n", array_filter($body, fn($l) => $l !== NULL)),
    ];
    try {
      $this->mailManager->mail('bos_service_request', 'contact_notification', self::NOTIFY, 'en', $params);
    }
    catch (\Throwable $e) {
      $this->srLogger->warning('Contact backstop email failed for @ref: @m', ['@ref' => $ref, '@m' => $e->getMessage()]);
    }
  }

}
