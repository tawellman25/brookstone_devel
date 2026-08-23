<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Check your service week" (/winterize/week) — the pc26a ("already on our
 * list") postcard destination.
 *
 * DISCLOSURE GATE (§6.0-aligned): the scheduled week is revealed ONLY when the
 * visitor's submitted phone/email CORROBORATES the matched property's on-file
 * contact — a street address alone never reveals anything, and a non-corroborated
 * attempt gets an identical neutral answer whether or not the address exists in
 * BOS (enumeration control). Matching is server-side; no property element is
 * ever rendered. reCAPTCHA + per-IP flood limit brute-forcing contact combos.
 * Owner-accepted tradeoff (2026-08-23): someone who knows a customer's address
 * AND phone/email can see that customer's week.
 */
final class CheckWeekForm extends FormBase {

  protected $entityTypeManager;
  protected $configFactory;
  protected $matcher;
  protected $eligibility;
  protected $flood;
  protected $requestStack;
  protected $srLogger;

  public const BUNDLE = 'sprinkler_winterizing';
  private const NON_BLOCKING_WO = [1098];
  private const FLOOD_EVENT = 'bos_service_request.check_week_ip';

  public static function create(ContainerInterface $container): static {
    $i = new static();
    $i->entityTypeManager = $container->get('entity_type.manager');
    $i->configFactory = $container->get('config.factory');
    $i->matcher = $container->get('bos_service_request.property_matcher');
    $i->eligibility = $container->get('bos_service_request.eligibility');
    $i->flood = $container->get('flood');
    $i->requestStack = $container->get('request_stack');
    $i->srLogger = $container->get('logger.channel.bos_service_request');
    return $i;
  }

  public function getFormId(): string {
    return 'bos_winterize_check_week';
  }

  private function cfg(): array {
    return $this->configFactory->get('bos_service_request.settings')->get('bundles.' . self::BUNDLE) ?? [];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $phone = (string) $this->configFactory->get('bos_service_request.settings')->get('office_phone');

    if ($done = $form_state->get('check_done')) {
      $paras = '';
      foreach (preg_split('/\n\n+/', $done) as $p) {
        $p = trim($p);
        if ($p !== '') {
          $paras .= '<p>' . Html::escape($p) . '</p>';
        }
      }
      $signup = Url::fromRoute('bos_service_request.winterize')->toString();
      $form['confirmation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['winterize-confirmation']],
        'msg' => ['#markup' => Markup::create('<h2>Your winterization</h2>' . $paras
          . '<p style="margin-top:1.2rem"><a class="bo-btn" href="' . Html::escape($signup) . '">Not on the list? Get on it &rarr;</a></p>')],
      ];
      return $form;
    }

    $form['#attributes']['class'][] = 'winterize-form';
    $form['#attributes']['class'][] = 'bo-form-card';
    $form['intro'] = [
      '#markup' => '<p style="margin:0 0 4px">Enter your details and we\'ll show you the week we plan to be in your area. To protect your information, we confirm against the phone or email we have on file.</p>',
    ];
    $form['row_name'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2']],
      'submitted_name' => ['#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE, '#maxlength' => 255],
      'submitted_phone' => ['#type' => 'tel', '#title' => $this->t('Phone on file'), '#maxlength' => 32],
    ];
    $form['row_addr'] = [
      '#type' => 'container', '#attributes' => ['class' => ['bo-grid-2-1']],
      'submitted_address' => ['#type' => 'textfield', '#title' => $this->t('Service address'), '#required' => TRUE, '#maxlength' => 255],
      'submitted_zip' => ['#type' => 'textfield', '#title' => $this->t('ZIP'), '#required' => TRUE, '#maxlength' => 10],
    ];
    $form['submitted_email'] = ['#type' => 'email', '#title' => $this->t('Email on file'), '#maxlength' => 255, '#description' => $this->t('Enter the phone OR email we have for your account.')];
    $form['captcha'] = ['#type' => 'captcha', '#captcha_type' => 'recaptcha/reCAPTCHA'];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Check my week'), '#attributes' => ['class' => ['winterize-submit']]];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->requestStack->getCurrentRequest()->getClientIp();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, 15, 3600, $ip)) {
      $form_state->setErrorByName('submitted_name', $this->t('Too many lookups from your connection. Please try again later or call the office.'));
      return;
    }
    $phone = trim((string) $form_state->getValue('submitted_phone'));
    $email = trim((string) $form_state->getValue('submitted_email'));
    if ($phone === '' && $email === '') {
      $form_state->setErrorByName('submitted_email', $this->t('Enter the phone or email on your account so we can confirm it\'s you.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $cfg = $this->cfg();
    $serviceTermId = (int) ($cfg['service_term_id'] ?? 0);
    $serviceYear = (int) ($cfg['service_year'] ?? (int) date('Y'));
    $phone = (string) $this->configFactory->get('bos_service_request.settings')->get('office_phone');

    $req = $this->requestStack->getCurrentRequest();
    // Register the attempt (success or fail) — caps enumeration per IP.
    $this->flood->register(self::FLOOD_EVENT, 3600, $req->getClientIp());
    // §6.0: ignore any injected property identifier.
    foreach (['property_id', 'field_property'] as $k) {
      if ($req->request->has($k) || $req->query->has($k)) {
        $this->srLogger->warning('Ignored injected @k on /winterize/week from @ip.', ['@k' => $k, '@ip' => $req->getClientIp()]);
      }
    }

    $clean = fn(string $key) => mb_substr(trim((string) $form_state->getValue($key)), 0, 255);
    $name = $clean('submitted_name');
    $address = $clean('submitted_address');
    $zip = $clean('submitted_zip');
    $phoneIn = $clean('submitted_phone');
    $email = $clean('submitted_email');

    $match = $this->matcher->match($name, $address, $zip, $phoneIn, $email);
    $propertyId = ($match['status'] === 'matched') ? (int) $match['property_id'] : NULL;
    $corroborated = $propertyId && $this->matcher->contactCorroborates($propertyId, $phoneIn, $email);

    if ($corroborated) {
      $week = $this->scheduledWeek($propertyId, $serviceTermId, $serviceYear);
      if ($week) {
        $message = strtr("Good news — you're on our winterization list, and we plan to be in your area the week of @week. We'll winterize your system that week. If a hard freeze is possible before then, cover your backflow or pump.\n\nQuestions? Call us at @phone.", ['@week' => $week, '@phone' => $phone]);
      }
      else {
        $message = strtr("You're on our winterization list. We haven't set your exact week yet — we build routes through October and will call you with the week we plan to be in your area.\n\nQuestions, or need a specific date? Call us at @phone.", ['@phone' => $phone]);
      }
      $this->srLogger->info('Check-week: corroborated reveal for property @p.', ['@p' => $propertyId]);
    }
    else {
      // Neutral — identical whether or not the address exists (enumeration control).
      $message = strtr("We couldn't confirm a scheduled winterization for the details you entered. If you're already a customer, call the office at @phone and we'll confirm your week.\n\nIf you're not on our list yet, you can sign up below.", ['@phone' => $phone]);
    }

    $form_state->set('check_done', $message);
    $form_state->setRebuild(TRUE);
  }

  /**
   * The scheduled week ("Mon MM/DD/YYYY") for the property's winterizing WO, or
   * NULL if there's no scheduled date yet. Read-only.
   */
  private function scheduledWeek(int $propertyId, int $serviceTermId, int $serviceYear): ?string {
    $bundle = $this->eligibility->resolveWorkOrderBundle($serviceTermId) ?: self::BUNDLE;
    $tz = new \DateTimeZone(date_default_timezone_get());
    $start = $this->cfg()['service_year_start'] ?? "$serviceYear-08-01";
    $end = $this->cfg()['service_year_end'] ?? (($serviceYear + 1) . '-01-31');
    $winStart = (new DrupalDateTime($start . ' 00:00:00', $tz))->getTimestamp();
    $winEnd = (new DrupalDateTime($end . ' 23:59:59', $tz))->getTimestamp();

    $woStorage = $this->entityTypeManager->getStorage('work_order');
    $woIds = $woStorage->getQuery()->accessCheck(FALSE)
      ->condition('type', $bundle)->condition('field_property', $propertyId)
      ->condition('created', $winStart, '>=')->condition('created', $winEnd, '<=')
      ->sort('id', 'DESC')->execute();
    foreach ($woStorage->loadMultiple($woIds) as $wo) {
      $st = (!$wo->get('field_status')->isEmpty()) ? (int) $wo->get('field_status')->target_id : 0;
      if (in_array($st, self::NON_BLOCKING_WO, TRUE)) {
        continue;
      }
      $sids = $this->entityTypeManager->getStorage('scheduling')->getQuery()->accessCheck(FALSE)
        ->condition('field_work_order', $wo->id())->sort('id', 'DESC')->range(0, 1)->execute();
      foreach ($this->entityTypeManager->getStorage('scheduling')->loadMultiple($sids) as $s) {
        if (!$s->get('field_date')->isEmpty()) {
          $ts = (int) $s->get('field_date')->value;
          $dt = DrupalDateTime::createFromTimestamp($ts, $tz);
          $monday = clone $dt;
          $dow = (int) $dt->format('N');
          if ($dow > 1) {
            $monday->modify('-' . ($dow - 1) . ' days');
          }
          return $monday->format('D m/d/Y');
        }
      }
      // WO exists but not scheduled yet.
      return NULL;
    }
    return NULL;
  }

}
