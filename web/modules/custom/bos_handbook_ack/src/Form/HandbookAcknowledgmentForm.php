<?php

declare(strict_types=1);

namespace Drupal\bos_handbook_ack\Form;

use Drupal\bos_handbook_ack\Service\HandbookAckService;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * "I acknowledge the Team Handbook" — one record per staff member per version.
 */
final class HandbookAcknowledgmentForm extends FormBase {

  // Property names avoid FormBase's own ($requestStack, $currentUser, …).
  public function __construct(
    private readonly HandbookAckService $ackSvc,
    private readonly AccountProxyInterface $account,
    private readonly RequestStack $reqStack,
    private readonly DateFormatterInterface $dates,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bos_handbook_ack.service'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('date.formatter'),
    );
  }

  public function getFormId(): string {
    return 'bos_handbook_acknowledgment_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'bos_handbook_ack/ack';
    $form['#cache']['max-age'] = 0;

    $is_staff = (bool) array_intersect($this->account->getRoles(), HandbookAckService::STAFF_ROLES);
    if (!$this->account->isAuthenticated() || !$is_staff) {
      $form['msg'] = ['#markup' => '<div class="hback"><p>Please log in as a staff member to acknowledge the handbook.</p></div>'];
      return $form;
    }

    $version = $this->ackSvc->currentVersion();
    if ($version === '') {
      $form['msg'] = ['#markup' => '<div class="hback"><p>No handbook version is set yet — please contact the office.</p></div>'];
      return $form;
    }

    $existing = $this->ackSvc->acknowledgmentFor((int) $this->account->id(), $version);
    if ($existing) {
      $ts = $existing->get('field_acknowledged_on')->value;
      $when = $ts ? $this->dates->format((int) $ts, 'custom', 'm/d/Y g:i A') : '';
      $form['done'] = [
        '#markup' => '<div class="hback hback--done">'
          . '<p class="hback__check"><strong>&#10003; You have acknowledged the Team Handbook.</strong></p>'
          . '<p>Version: ' . Html::escape($version) . '<br>'
          . 'On: ' . Html::escape($when) . '<br>'
          . 'Signed: ' . Html::escape((string) $existing->get('field_typed_name')->value) . '</p>'
          . '<p class="hback__note">Your acknowledgment is on file. If the handbook is updated to a new version, you\'ll be asked to acknowledge again here.</p>'
          . '</div>',
      ];
      return $form;
    }

    $form['statement'] = [
      '#markup' => '<div class="hback__statement"><p>By typing my name and clicking below, I acknowledge that I have read, understand, and agree to abide by the Brookstone Outdoors <strong>Team Handbook</strong> (' . Html::escape($version) . '). I understand this is the same content as the printed handbook.</p></div>',
    ];
    $form['typed_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Type your full name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => ['autocomplete' => 'off', 'placeholder' => 'e.g. Jane Smith'],
    ];
    $form['version'] = ['#type' => 'value', '#value' => $version];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('I have read and agree'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (trim((string) $form_state->getValue('typed_name')) === '') {
      $form_state->setErrorByName('typed_name', $this->t('Please type your name to sign.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->account->id();
    $version = (string) $form_state->getValue('version');
    if ($version === '' || !array_intersect($this->account->getRoles(), HandbookAckService::STAFF_ROLES)) {
      return;
    }
    if ($this->ackSvc->hasAcknowledged($uid, $version)) {
      $this->messenger()->addStatus($this->t('Your acknowledgment was already on file.'));
      return;
    }
    $ip = (string) ($this->reqStack->getCurrentRequest()?->getClientIp() ?? '');
    $this->ackSvc->record($uid, $version, trim((string) $form_state->getValue('typed_name')), $ip);
    $this->messenger()->addStatus($this->t('Thank you — your acknowledgment of the Team Handbook (@v) has been recorded.', ['@v' => $version]));
  }

}
