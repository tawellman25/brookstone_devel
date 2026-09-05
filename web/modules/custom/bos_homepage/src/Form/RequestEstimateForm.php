<?php

declare(strict_types=1);

namespace Drupal\bos_homepage\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public "Request an Estimate" form → creates an estimate_request design-build
 * lead (flows into the Estimates pipeline; estimate_intake cascades an Estimate
 * + Contact). Same guardrails as the winterize/fall-cleanup forms: reCAPTCHA,
 * per-IP flood, server-side property match. Injected services are declared
 * (not promoted) so a captcha rebuild can re-inject them.
 */
final class RequestEstimateForm extends FormBase {

  protected $entityTypeManager;
  protected $configFactory;
  protected $matcher;
  protected $flood;
  protected $requestStack;
  protected $srLogger;

  public static function create(ContainerInterface $container): static {
    $i = new static();
    $i->entityTypeManager = $container->get('entity_type.manager');
    $i->configFactory = $container->get('config.factory');
    $i->matcher = $container->get('bos_service_request.property_matcher');
    $i->flood = $container->get('flood');
    $i->requestStack = $container->get('request_stack');
    $i->srLogger = $container->get('logger.channel.default');
    return $i;
  }

  public function getFormId(): string {
    return 'bos_homepage_request_estimate';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $phone = (string) $this->configFactory->get('bos_service_request.settings')->get('office_phone');

    if ($done = $form_state->get('estimate_done')) {
      $form['confirmation'] = ['#markup' => '<div class="bo-form-card"><h2>Thank you</h2><p>' . Html::escape($done) . '</p></div>'];
      $form['#attached']['library'][] = 'bos_homepage/homepage';
      return $form;
    }

    $form['#attributes']['class'][] = 'bo-form-card';
    $form['#attached']['library'][] = 'bos_homepage/homepage';

    $form['intro'] = ['#markup' => '<p class="home-lead">Tell us about your project and we will come look at it — no charge, no obligation. We serve Delta and Montrose counties.</p>'];

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
    $form['row_addr'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2-1']],
      'address' => ['#type' => 'textfield', '#title' => $this->t('Property address'), '#required' => TRUE, '#maxlength' => 255],
      'zip' => ['#type' => 'textfield', '#title' => $this->t('ZIP'), '#required' => TRUE, '#maxlength' => 10],
    ];
    $form['interest'] = [
      '#type' => 'select',
      '#title' => $this->t('What are you interested in?'),
      '#options' => [
        'Landscaping' => $this->t('Landscape design & installation'),
        'Landscape and Lawn Care' => $this->t('Lawn care'),
        'Sprinkler Systems' => $this->t('Irrigation'),
        'Lighting' => $this->t('Landscape lighting'),
        'Snow Removal' => $this->t('Snow removal'),
        'Landscaping_other' => $this->t('Something else / not sure'),
      ],
      '#default_value' => 'Landscaping',
    ];
    $form['description'] = [
      '#type' => 'textarea', '#title' => $this->t('Tell us about the project'), '#rows' => 4, '#required' => TRUE,
      '#description' => $this->t('What you are hoping to do, roughly when, and anything we should know.'),
    ];

    $form['captcha'] = ['#type' => 'captcha', '#captcha_type' => 'recaptcha/reCAPTCHA'];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Request my estimate'), '#attributes' => ['class' => ['bo-btn']]];
    $form['phone_note'] = ['#markup' => '<p class="home-lead">Prefer to call? <a href="tel:' . preg_replace('/[^0-9+]/', '', $phone) . '">' . Html::escape($phone) . '</a></p>'];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->requestStack->getCurrentRequest()->getClientIp();
    if (!$this->flood->isAllowed('bos_homepage.estimate_ip', 5, 3600, $ip)) {
      $form_state->setErrorByName('', $this->t('Too many requests from this connection. Please call the office.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $get = fn(string $k) => mb_substr(trim((string) $form_state->getValue($k)), 0, 1000);
    $first = $get('first_name');
    $last = $get('last_name');
    $name = trim($first . ' ' . $last);
    $address = $get('address');
    $zip = $get('zip');
    $phoneIn = $get('phone');
    $email = $get('email');
    $desc = $get('description');
    $interestRaw = (string) $form_state->getValue('interest');
    $serviceName = str_replace('_other', '', $interestRaw) ?: 'Landscaping';

    $match = $this->matcher->match($last, $address, $zip, $phoneIn, $email);
    $propertyId = ($match['status'] === 'matched') ? $match['property_id'] : NULL;

    $statusTid = $this->termIdByName('estimate_request_status', 'New - Gathering Info');
    $serviceTid = $this->termIdByName('services', $serviceName) ?: $this->termIdByName('services', 'Landscaping');

    $values = [
      'type' => 'standard',
      'uid' => 0,
      'field_priority' => 'normal',
      'field_requestor_name' => $name,
      'field_requestor_address' => trim($address . ($zip ? ', ' . $zip : '')),
      'field_requestor_phone' => $phoneIn,
      'field_requestor_email' => $email,
      'field_client_requested' => [
        'value' => 'Website estimate request (' . $serviceName . '):' . "\n\n" . $desc,
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

    $this->flood->register('bos_homepage.estimate_ip', 3600, $this->requestStack->getCurrentRequest()->getClientIp());
    $this->srLogger->info('Public estimate request → estimate_request @id (service @s, matched property @p).', [
      '@id' => $lead->id(), '@s' => $serviceName, '@p' => $propertyId ?: 'none',
    ]);

    $phone = (string) $this->configFactory->get('bos_service_request.settings')->get('office_phone');
    $form_state->set('estimate_done', 'Thank you — your estimate request is in. We will call you to set up a time to come look at the property. If you need us sooner, call ' . $phone . '.');
    $form_state->setRebuild(TRUE);
  }

  private function termIdByName(string $vid, string $name): ?int {
    $t = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
    return $t ? (int) reset($t)->id() : NULL;
  }

}
