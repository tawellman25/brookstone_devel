<?php

declare(strict_types=1);

namespace Drupal\bos_testimonial\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Public testimonial form. Creates a PENDING testimonial record.
 *
 * Deliberately does NOT ask how the experience was and branch on the answer:
 * routing customers to Google or to a private form by sentiment is review gating,
 * which violates Google's prohibited-content policy. Both paths are offered to
 * everyone on the page that hosts this form.
 *
 * Injected services are DECLARED, not constructor-promoted — a cacheable form's
 * DependencySerializationTrait cannot re-inject promoted readonly properties on
 * unserialize (see Governance/drupal_bos_gotchas.md).
 */
class ReviewForm extends FormBase {

  protected EntityTypeManagerInterface $etm;
  protected FloodInterface $floodService;
  protected RequestStack $reqStack;

  public static function create(ContainerInterface $container): static {
    $form = new static();
    $form->etm = $container->get('entity_type.manager');
    $form->floodService = $container->get('flood');
    $form->reqStack = $container->get('request_stack');
    return $form;
  }

  public function getFormId(): string {
    return 'bos_testimonial_review_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->reqStack->getCurrentRequest();

    $form['#attributes']['class'][] = 'review-form';

    $form['intro'] = [
      '#markup' => '<p class="review-form__intro">Prefer to send it to us directly? Write it here and we may feature it on our site. We will not publish anything without reading it first.</p>',
    ];

    $form['field_testimonial_by'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#required' => TRUE,
      '#maxlength' => 120,
      '#description' => $this->t('As you would like it shown if we feature your review.'),
    ];

    $form['field_testimony'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your review'),
      '#required' => TRUE,
      '#rows' => 6,
    ];

    // Service is optional context, not a gate.
    $services = [];
    try {
      $terms = $this->etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'services']);
      foreach ($terms as $term) {
        $services[$term->id()] = $term->label();
      }
      natcasesort($services);
    }
    catch (\Throwable $e) {
      $services = [];
    }
    if ($services) {
      $form['field_testimony_service'] = [
        '#type' => 'select',
        '#title' => $this->t('Which service was this about?'),
        '#options' => ['' => $this->t('- Optional -')] + $services,
        '#required' => FALSE,
      ];
    }

    $form['field_submitter_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email'),
      '#required' => FALSE,
      '#description' => $this->t('Optional. Only so we can thank you or check a detail — it is never published.'),
    ];

    // Attribution carried by the QR, if any.
    $form['wo'] = ['#type' => 'value', '#value' => (int) $request->query->get('wo', 0)];
    $form['campaign'] = ['#type' => 'value', '#value' => substr((string) $request->query->get('c', ''), 0, 32)];

    $form['captcha'] = [
      '#type' => 'captcha',
      '#captcha_type' => 'recaptcha/reCAPTCHA',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send your review'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->reqStack->getCurrentRequest()->getClientIp();
    if (!$this->floodService->isAllowed('bos_testimonial.submit', 5, 3600, $ip)) {
      $form_state->setErrorByName('field_testimony', $this->t('That is several reviews from this connection in a short time. Please call us on 970-835-9661 instead.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->reqStack->getCurrentRequest()->getClientIp();
    $this->floodService->register('bos_testimonial.submit', 3600, $ip);

    $values = [
      'type' => 'client',
      'field_testimonial_by' => trim((string) $form_state->getValue('field_testimonial_by')),
      'field_testimony' => [
        'value' => trim((string) $form_state->getValue('field_testimony')),
        'format' => 'plain_text',
      ],
      // The presave backstop forces this anyway; set it explicitly so the intent
      // is visible at the call site.
      'field_status' => 'pending',
    ];
    $service = $form_state->getValue('field_testimony_service');
    if (!empty($service)) {
      $values['field_testimony_service'] = (int) $service;
    }
    $email = trim((string) $form_state->getValue('field_submitter_email'));
    if ($email !== '') {
      $values['field_submitter_email'] = $email;
    }
    $wo = (int) $form_state->getValue('wo');
    if ($wo > 0 && $this->etm->getStorage('work_order')->load($wo)) {
      $values['field_work_order'] = $wo;
    }

    try {
      $this->etm->getStorage('testimonial')->create($values)->save();
      $this->messenger()->addStatus($this->t('Thank you — that means a lot. We read every one, and we will be in touch if we would like to feature it.'));
    }
    catch (\Throwable $e) {
      $this->logger('bos_testimonial')->error('Testimonial submission failed: @m', ['@m' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Something went wrong saving that. Please call us on 970-835-9661 and we will take it down.'));
    }
  }

}
