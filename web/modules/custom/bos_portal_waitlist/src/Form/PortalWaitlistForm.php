<?php

namespace Drupal\bos_portal_waitlist\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Xss;

/**
 * Customer-portal waitlist form (right column of the login page).
 *
 * NOT an account signup: no password, no user creation, no auth. Writes to the
 * Contact entity (the consent home). Submitting is a request to be emailed when
 * accounts open, so it grants service-email consent; marketing/SMS only on the
 * explicit ticks. Portal interest itself is a product signal recorded as plain
 * Contact fields, never in the consent log.
 *
 * Services are DECLARED (not promoted-readonly): this form is cacheable and
 * DependencySerializationTrait must be able to re-inject them (BOS gotcha).
 */
class PortalWaitlistForm extends FormBase {

  protected $etm;
  protected $floodSvc;
  protected $reqStack;
  protected $db;

  public static function create($container) {
    $form = new static();
    $form->etm = $container->get('entity_type.manager');
    $form->floodSvc = $container->get('flood');
    $form->reqStack = $container->get('request_stack');
    $form->db = $container->get('database');
    return $form;
  }

  public function getFormId() {
    return 'bos_portal_waitlist_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes']['class'][] = 'bo-waitlist-form';

    $form['names'] = ['#type' => 'container', '#attributes' => ['class' => ['bo-waitlist-row']]];
    $form['names']['first_name'] = [
      '#type' => 'textfield', '#title' => $this->t('First name'), '#required' => TRUE,
    ];
    $form['names']['last_name'] = [
      '#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE,
    ];
    $form['email'] = [
      '#type' => 'email', '#title' => $this->t('Email'), '#required' => TRUE,
    ];
    $form['mobile'] = [
      '#type' => 'tel', '#title' => $this->t('Mobile number'),
      '#description' => $this->t('Only needed if you want scheduling texts.'),
    ];
    $form['service_address'] = [
      '#type' => 'textfield', '#title' => $this->t('Service address'),
    ];
    $form['already_customer'] = [
      '#type' => 'radios', '#title' => $this->t('Are you already a Brookstone customer?'),
      '#options' => ['yes' => $this->t('Yes'), 'no' => $this->t('No'), 'not_sure' => $this->t('Not sure')],
    ];

    $form['opt_seasonal'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also send me seasonal reminders by email — winterization in the fall, cleanup, snow contracts, spring turn-on. A few times a year, and you can stop them any time.'),
    ];
    // Scheduling-text tick only shows once a mobile number is entered.
    $form['opt_scheduling_sms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Text me about scheduling — arrival windows and day-of updates for work at my property.'),
      '#states' => ['visible' => [':input[name="mobile"]' => ['filled' => TRUE]]],
    ];

    $form['captcha'] = ['#type' => 'captcha', '#captcha_type' => 'recaptcha/reCAPTCHA'];

    $form['disclaimer'] = [
      '#markup' => '<p class="bo-waitlist-fineprint">' . Xss::filter($this->t('If you are already our customer, we may email you about your own work — scheduling, invoices, and account notices. We will not add you to our seasonal list unless you tick the box above, and every message we send has a way to stop it.')) . '</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit', '#value' => $this->t('Tell me when it is ready'),
      '#attributes' => ['class' => ['bo-waitlist-submit']],
    ];
    $form['#cache']['contexts'][] = 'url.query_args:c';
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $ip = $this->reqStack->getCurrentRequest()->getClientIp();
    if (!$this->floodSvc->isAllowed('bos_portal_waitlist.ip', 10, 3600, $ip)) {
      $form_state->setErrorByName('', $this->t('Too many submissions from this connection. Please try again later.'));
      return;
    }
    $email = strtolower(trim((string) $form_state->getValue('email')));
    if ($email !== '' && !$this->floodSvc->isAllowed('bos_portal_waitlist.email', 3, 86400, $email)) {
      $form_state->setErrorByName('email', $this->t('This email is already on the list — we will be in touch.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $ip = $this->reqStack->getCurrentRequest()->getClientIp();
    $this->floodSvc->register('bos_portal_waitlist.ip', 3600, $ip);
    $email = strtolower(trim((string) $form_state->getValue('email')));
    $this->floodSvc->register('bos_portal_waitlist.email', 86400, $email);

    $first = trim((string) $form_state->getValue('first_name'));
    $last = trim((string) $form_state->getValue('last_name'));
    $mobile = trim((string) $form_state->getValue('mobile'));
    $addr = trim((string) $form_state->getValue('service_address'));
    $alreadyCust = (string) $form_state->getValue('already_customer');
    $wantSeasonal = (bool) $form_state->getValue('opt_seasonal');
    $wantSms = (bool) $form_state->getValue('opt_scheduling_sms') && $mobile !== '';
    $campaign = $this->campaignCode();

    $storage = $this->etm->getStorage('contacts');
    $contact = $this->matchContact($email, $last, $mobile);

    if ($contact) {
      // Fabricated-email caution: the submitted address is the real one.
      $stored = strtolower(trim((string) ($contact->get('field_email')->value ?? '')));
      $storedIsFake = $stored === '' || str_ends_with($stored, '@brookstoneoutdoors.com') || str_ends_with($stored, '@sewardslandscape.com');
      if ($storedIsFake) {
        $contact->set('field_email', $email);
      }
      // Fill only-empty name fields; do not clobber a good existing name.
      if ($contact->get('field_first_name')->isEmpty() && $first !== '') { $contact->set('field_first_name', $first); }
      if ($contact->get('field_last_name')->isEmpty() && $last !== '') { $contact->set('field_last_name', $last); }
    }
    else {
      $contact = $storage->create([
        'type' => 'contact',
        'title' => trim("$first $last"),
        'field_first_name' => $first,
        'field_last_name' => $last,
        'field_email' => $email,
      ]);
    }

    // Consent flags — never flip an existing opt-out; only ever set TRUE here.
    $changed = FALSE;
    $changed = $this->grantFlag($contact, 'field_opt_in_service_email', TRUE) || $changed;      // submit = notify request
    $changed = $this->grantFlag($contact, 'field_opt_in_marketing_email', $wantSeasonal) || $changed;
    $changed = $this->grantFlag($contact, 'field_opt_in_service_sms', $wantSms) || $changed;
    if ($changed) {
      $contact->set('field_consent_updated', (new DrupalDateTime('now', 'UTC'))->format('Y-m-d\TH:i:s'));
      $contact->set('field_consent_source', 'web_form');
      // Attribution hints for bos_consent_log (records source=web_form + IP).
      $contact->_consent_source = 'web_form';
      $contact->_consent_ip = $ip;
      $contact->_consent_note = 'portal waitlist signup';
    }

    // Portal interest — PRODUCT SIGNAL, not consent (never in the consent log).
    $contact->set('field_portal_interest', TRUE);
    $contact->set('field_portal_interest_date', (new DrupalDateTime('now', 'UTC'))->format('Y-m-d\TH:i:s'));
    $note = [];
    if ($mobile !== '') { $note[] = 'Mobile: ' . $mobile; }
    if ($addr !== '') { $note[] = 'Service address: ' . $addr; }
    if ($alreadyCust !== '') { $note[] = 'Already a customer: ' . $alreadyCust; }
    $note[] = 'Campaign: ' . $campaign;
    $note[] = 'Signed up: ' . (new DrupalDateTime('now'))->format('m/d/Y g:i A');
    $contact->set('field_portal_interest_note', implode("\n", $note));

    $contact->save();

    $this->messenger()->addStatus($this->t('Thank you — you are on the list. When customer accounts are open, we will email you.'));
    $form_state->setRedirect('user.login', [], ['fragment' => 'customer-accounts']);
  }

  /**
   * Set a consent flag to TRUE unless it is already an explicit opt-out.
   * Returns TRUE if it changed the value.
   */
  protected function grantFlag($contact, string $field, bool $want): bool {
    if (!$want || !$contact->hasField($field)) {
      return FALSE;
    }
    $cur = $contact->get($field);
    // Explicit opt-out (value present and false) is never silently flipped on.
    if (!$cur->isEmpty() && (int) $cur->value === 0) {
      \Drupal::logger('bos_portal_waitlist')->notice('Waitlist submit left an existing opt-out untouched on @f.', ['@f' => $field]);
      return FALSE;
    }
    if (!$cur->isEmpty() && (int) $cur->value === 1) {
      return FALSE; // already opted in
    }
    $contact->set($field, TRUE);
    return TRUE;
  }

  /**
   * Match an existing Contact: email → last name + digits-phone. No match = NULL.
   */
  protected function matchContact(string $email, string $last, string $mobile) {
    $storage = $this->etm->getStorage('contacts');
    // 1) exact email (case-insensitive).
    if ($email !== '') {
      $cid = $this->db->query("SELECT entity_id FROM {contacts__field_email} WHERE bundle='contact' AND LOWER(TRIM(field_email_value))=:e LIMIT 1", [':e' => $email])->fetchField();
      if ($cid) { return $storage->load($cid); }
    }
    // 2) last name + digits-only phone (via contact -> field_phone_number -> phone_number).
    $digits = preg_replace('/\D+/', '', $mobile);
    if ($last !== '' && strlen($digits) >= 10) {
      $cid = $this->db->query("
        SELECT c.id
        FROM {contacts_field_data} c
        JOIN {contacts__field_last_name} ln ON ln.entity_id=c.id AND ln.bundle='contact'
        JOIN {contacts__field_phone_number} pr ON pr.entity_id=c.id AND pr.bundle='contact'
        JOIN {phone_number__field_phone_number} pv ON pv.entity_id=pr.field_phone_number_target_id
        WHERE c.type='contact'
          AND LOWER(TRIM(ln.field_last_name_value))=:ln
          AND REGEXP_REPLACE(pv.field_phone_number_value,'[^0-9]','')=:ph
        LIMIT 1", [':ln' => strtolower($last), ':ph' => $digits])->fetchField();
      if ($cid) { return $storage->load($cid); }
    }
    return NULL;
  }

  /**
   * Campaign code from ?c= (allowlisted), default portal26.
   */
  protected function campaignCode(): string {
    $c = (string) $this->reqStack->getCurrentRequest()->query->get('c', '');
    $c = preg_replace('/[^a-z0-9_\-]/i', '', $c);
    return $c !== '' ? $c : 'portal26';
  }

}
