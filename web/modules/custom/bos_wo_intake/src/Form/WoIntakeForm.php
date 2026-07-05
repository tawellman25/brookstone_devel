<?php

declare(strict_types=1);

namespace Drupal\bos_wo_intake\Form;

use Drupal\bos_wo_intake\Service\WorkOrderIntakeService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gate 2B — the /wo-intake page. A thin, stateless consumer of 2A's
 * WorkOrderIntakeService::createFromText(). One textarea (Android keyboard mic
 * supplies dictation), a Create button, and an AJAX result region rendering the
 * four contract states. Candidate taps + "Create anyway" resubmit the ORIGINAL
 * text plus resolved ids — all state rides the form, none on the server.
 */
final class WoIntakeForm extends FormBase {

  public function __construct(
    private readonly WorkOrderIntakeService $intake,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bos_wo_intake.intake'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'bos_wo_intake_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $storage = $form_state->getStorage();
    $form['#attached']['library'][] = 'bos_wo_intake/intake';
    $form['#attributes']['class'][] = 'wo-intake-form';

    $form['command'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What do you need?'),
      '#title_display' => 'invisible',
      '#rows' => 3,
      '#default_value' => $storage['command'] ?? '',
      '#attributes' => [
        'placeholder' => $this->t('Repair WO for Smith, John on Brookdale — broken sprinkler'),
        'autofocus' => 'autofocus',
        'class' => ['wo-intake-textarea'],
        'autocapitalize' => 'sentences',
      ],
    ];

    // Result region sits BETWEEN the textarea and the button so candidates/cards
    // are immediately visible after submit (not below the fold in the modal).
    $form['result'] = [
      '#type' => 'container',
      '#weight' => 5,
      '#attributes' => ['id' => 'wo-intake-result', 'class' => ['wo-intake-result']],
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['#weight'] = 10;
    $form['actions']['create'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Work Order'),
      '#wo_action' => 'create',
      '#submit' => ['::submitIntake'],
      '#attributes' => ['class' => ['wo-intake-create']],
      '#ajax' => ['callback' => '::ajaxResult', 'wrapper' => 'wo-intake-result'],
    ];
    if (!empty($storage['result'])) {
      $form['result']['content'] = $this->renderResult($storage['result']);
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // All work happens in submitIntake (the AJAX submit handler).
  }

  /**
   * Shared submit handler for Create, candidate taps, and "Create anyway".
   */
  public function submitIntake(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $action = $trigger['#wo_action'] ?? 'create';
    $storage = $form_state->getStorage();
    $opts = $storage['opts'] ?? [];

    if ($action === 'create') {
      $command = trim((string) $form_state->getValue('command'));
      $opts = [];
    }
    else {
      $command = (string) ($storage['command'] ?? trim((string) $form_state->getValue('command')));
      if ($action === 'pick_property') {
        $opts['property_id'] = (int) $trigger['#wo_property_id'];
      }
      elseif ($action === 'pick_service') {
        $opts['service_term_id'] = (int) $trigger['#wo_service_term_id'];
      }
      elseif ($action === 'create_anyway') {
        $opts['allow_duplicate'] = TRUE;
      }
    }

    // Only pass set keys to the stateless service.
    $callOpts = [];
    foreach (['property_id', 'service_term_id'] as $k) {
      if (!empty($opts[$k])) {
        $callOpts[$k] = $opts[$k];
      }
    }
    if (!empty($opts['allow_duplicate'])) {
      $callOpts['allow_duplicate'] = TRUE;
    }

    if ($command === '') {
      $result = ['status' => 'error', 'error' => ['code' => 'empty', 'message' => (string) $this->t('Say or type a command first.')]];
    }
    else {
      $result = $this->intake->createFromText($command, $this->currentUser, $callOpts);
    }

    // A fresh create clears any prior resolved ids; a successful create clears
    // the carried opts so the next command starts clean.
    if (($result['status'] ?? '') === 'created') {
      $opts = [];
    }

    $form_state->setStorage(['command' => $command, 'opts' => $opts, 'result' => $result]);
    $form_state->setRebuild(TRUE);
  }

  /**
   * AJAX callback — replace the result region.
   */
  public function ajaxResult(array &$form, FormStateInterface $form_state): array {
    return $form['result'];
  }

  // ==========================================================================
  // Result rendering (the four contract states).
  // ==========================================================================

  private function renderResult(array $r): array {
    $out = ['#type' => 'container'];
    $status = $r['status'] ?? ($r['success'] ?? 'error');

    // Trust signal — always echo what the parser heard.
    if (!empty($r['extracted'])) {
      $out['heard'] = $this->heardLine($r['extracted']);
    }

    switch ($status) {
      case 'created':
        $out['card'] = $this->createdCard($r);
        break;

      case 'blocked':
        $out['card'] = $this->blockedCard($r);
        break;

      case 'ambiguous':
        if (($r['piece'] ?? '') === 'service') {
          $out['cards'] = empty($r['candidates'])
            ? $this->servicePicker()
            : $this->serviceCandidates($r['candidates']);
        }
        else {
          $out['cards'] = $this->propertyCandidates($r['candidates'] ?? []);
        }
        break;

      default:
        $out['card'] = $this->messageCard($this->t('Could not complete'), (string) ($r['error']['message'] ?? 'Unknown error.'), 'error');
    }
    return $out;
  }

  private function heardLine(array $frag): array {
    $parts = [];
    foreach (['service_phrase' => 'service', 'name' => 'name', 'street' => 'street', 'town' => 'town'] as $k => $label) {
      if (!empty($frag[$k])) {
        $parts[] = $label . '=' . $frag[$k];
      }
    }
    if (!empty($frag['complaint'])) {
      $parts[] = 'note="' . $frag['complaint'] . '"';
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Understood: @h', ['@h' => $parts ? implode(', ', $parts) : $this->t('(nothing recognized)')]),
      '#attributes' => ['class' => ['wo-intake-heard']],
    ];
  }

  private function createdCard(array $r): array {
    $wo = $this->entityTypeManager->getStorage('work_order')->load($r['work_order']['id']);
    $nickname = $wo && !$wo->get('field_property')->isEmpty() && $wo->get('field_property')->entity
      ? (string) $wo->get('field_property')->entity->get('field_nickname')->value : '';
    $serviceLbl = $wo && !$wo->get('field_service')->isEmpty() && $wo->get('field_service')->entity
      ? $wo->get('field_service')->entity->label() : ($r['work_order']['bundle'] ?? '');
    $woLabel = $r['work_order']['work_order_id'] ? ('#' . $r['work_order']['work_order_id']) : ('#' . $r['work_order']['id']);

    $card = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wo-intake-card', 'wo-intake-card--created']],
    ];
    $card['title'] = ['#markup' => '<div class="wo-intake-card__badge">' . $this->t('Created') . '</div>'];
    $card['body'] = [
      '#markup' => '<div class="wo-intake-card__title">' . htmlspecialchars($serviceLbl) . ' — ' . htmlspecialchars($nickname) . '</div>'
        . '<div class="wo-intake-card__meta">' . $this->t('Work Order @l · @s', ['@l' => $woLabel, '@s' => $r['work_order']['status']]) . '</div>',
    ];
    if (in_array('recent_terminal_noted', $r['flags'] ?? [], TRUE)) {
      $card['warn'] = ['#markup' => '<div class="wo-intake-card__warn">⚠ ' . $this->t('A recent completed WO exists for this property — verify this is a new issue, not a callback.') . '</div>'];
    }
    if ($wo) {
      $card['link'] = [
        '#type' => 'link',
        '#title' => $this->t('Open work order →'),
        '#url' => $wo->toUrl(),
        '#attributes' => ['class' => ['wo-intake-card__link'], 'target' => '_blank'],
      ];
    }
    return $card;
  }

  private function blockedCard(array $r): array {
    $e = $r['existing'];
    $woEntity = $this->entityTypeManager->getStorage('work_order')->load($e['id']);
    $label = $e['work_order_id'] ? ('#' . $e['work_order_id']) : ('#' . $e['id']);
    $card = ['#type' => 'container', '#attributes' => ['class' => ['wo-intake-card', 'wo-intake-card--blocked']]];
    $card['badge'] = ['#markup' => '<div class="wo-intake-card__badge wo-intake-card__badge--blocked">' . $this->t('Already open') . '</div>'];
    $card['body'] = ['#markup' => '<div class="wo-intake-card__title">' . $this->t('Work Order @l is already @s for this property.', ['@l' => $label, '@s' => $e['status']]) . '</div>'
      . '<div class="wo-intake-card__meta">' . $this->t('Reason: @r', ['@r' => $r['reason']]) . '</div>'];
    if ($woEntity) {
      $card['link'] = ['#type' => 'link', '#title' => $this->t('Open existing →'), '#url' => $woEntity->toUrl(), '#attributes' => ['class' => ['wo-intake-card__link'], 'target' => '_blank']];
    }
    $card['anyway'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create anyway'),
      '#wo_action' => 'create_anyway',
      '#submit' => ['::submitIntake'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['wo-intake-anyway']],
      '#ajax' => ['callback' => '::ajaxResult', 'wrapper' => 'wo-intake-result'],
    ];
    return $card;
  }

  private function propertyCandidates(array $candidates): array {
    if (!$candidates) {
      return $this->messageCard(
        $this->t('No matching property'),
        $this->t('Couldn’t find a property for that name. Try a last name with fewer extra words, check the spelling, or add the town — then Create again.'),
        'error'
      );
    }
    $wrap = ['#type' => 'container', '#attributes' => ['class' => ['wo-intake-candidates']]];
    $wrap['label'] = ['#markup' => '<div class="wo-intake-candidates__label">' . $this->t('Which property?') . '</div>'];
    foreach (array_slice($candidates, 0, 8) as $i => $c) {
      $sub = htmlspecialchars(trim(($c['street'] ?? '') . ' · ' . ($c['town'] ?? ''), ' ·'));
      $conflict = !empty($c['conflict'])
        ? '<div class="wo-intake-candidate__conflict">⚠ ' . $this->t('@f: expected @e, actual @a', ['@f' => $c['conflict']['field'], '@e' => $c['conflict']['expected'], '@a' => $c['conflict']['actual']]) . '</div>'
        : '';
      $wrap['c' . $i] = [
        '#type' => 'submit',
        '#value' => $c['name'],
        '#name' => 'pick_property_' . $c['id'],
        '#wo_action' => 'pick_property',
        '#wo_property_id' => $c['id'],
        '#submit' => ['::submitIntake'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['wo-intake-candidate'], 'data-sub' => $sub],
        '#prefix' => '<div class="wo-intake-candidate__wrap">',
        '#suffix' => '<div class="wo-intake-candidate__sub">' . $sub . '</div>' . $conflict . '</div>',
        '#ajax' => ['callback' => '::ajaxResult', 'wrapper' => 'wo-intake-result'],
      ];
    }
    return $wrap;
  }

  private function serviceCandidates(array $candidates): array {
    $wrap = ['#type' => 'container', '#attributes' => ['class' => ['wo-intake-candidates']]];
    $wrap['label'] = ['#markup' => '<div class="wo-intake-candidates__label">' . $this->t('Which service?') . '</div>'];
    foreach (array_slice($candidates, 0, 8) as $i => $c) {
      $wrap['s' . $i] = $this->serviceButton($c['term_id'], $c['name']);
    }
    return $wrap;
  }

  /**
   * The zero-candidate hard case: the full, client-filterable service picker.
   */
  private function servicePicker(): array {
    $ts = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $ts->getQuery()->accessCheck(FALSE)->condition('vid', 'services')
      ->condition('field_work_order_service', 1)->sort('name')->execute();
    $wrap = ['#type' => 'container', '#attributes' => ['class' => ['wo-intake-candidates', 'wo-intake-picker']]];
    $wrap['label'] = ['#markup' => '<div class="wo-intake-candidates__label">' . $this->t('Pick a service') . '</div>'];
    $wrap['filter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter services'),
      '#title_display' => 'invisible',
      '#attributes' => ['placeholder' => $this->t('Type to filter…'), 'class' => ['wo-intake-filter'], 'autocomplete' => 'off'],
    ];
    $wrap['options'] = ['#type' => 'container', '#attributes' => ['class' => ['wo-intake-picker__options']]];
    foreach ($ts->loadMultiple($ids) as $t) {
      $wrap['options']['o' . $t->id()] = $this->serviceButton((int) $t->id(), $t->label(), TRUE);
    }
    return $wrap;
  }

  private function serviceButton(int $tid, string $name, bool $filterable = FALSE): array {
    $attrs = ['class' => ['wo-intake-candidate']];
    if ($filterable) {
      $attrs['class'][] = 'wo-intake-service-option';
      $attrs['data-label'] = strtolower($name);
    }
    return [
      '#type' => 'submit',
      '#value' => $name,
      '#name' => 'pick_service_' . $tid,
      '#wo_action' => 'pick_service',
      '#wo_service_term_id' => $tid,
      '#submit' => ['::submitIntake'],
      '#limit_validation_errors' => [],
      '#attributes' => $attrs,
      '#ajax' => ['callback' => '::ajaxResult', 'wrapper' => 'wo-intake-result'],
    ];
  }

  private function messageCard($title, string $message, string $variant): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['wo-intake-card', 'wo-intake-card--' . $variant]],
      'body' => ['#markup' => '<div class="wo-intake-card__title">' . htmlspecialchars((string) $title) . '</div><div class="wo-intake-card__meta">' . htmlspecialchars($message) . '</div>'],
    ];
  }

}
