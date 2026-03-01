<?php

declare(strict_types=1);

namespace Drupal\estimate\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\estimate\Exception\EstimateConversionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Explicitly create/link a Contact from an Estimate Request.
 *
 * Governance:
 * - Contact creation MUST be explicit (button/action).
 * - Never auto-create contacts on Estimate Request save.
 * - Duplicate detection required before creation:
 *   - Email exact match (normalized)
 *   - Phone exact match (digits only)
 * - If duplicate exists -> link instead of create.
 * - Estimate Request must not overwrite existing Contact data automatically.
 */
final class EstimateRequestCreateOrLinkContactConfirmForm extends ConfirmFormBase {

  private EntityTypeManagerInterface $entityTypeManager;
  private LoggerInterface $logger;

  private int $estimateRequestId = 0;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, LoggerInterface $logger) {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('logger.channel.estimate'),
    );
  }

  public function getFormId(): string {
    return 'estimate_request_create_or_link_contact_confirm';
  }

  public function getQuestion(): string {
    return $this->t('Create or link a Contact for this Estimate Request?');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.estimate_request.canonical', ['estimate_request' => $this->estimateRequestId]);
  }

  public function getConfirmText(): string {
    return $this->t('Create/Link Contact');
  }

  /**
   * Route callback parameter is "estimate_request".
   */
  public function buildForm(array $form, FormStateInterface $form_state, $estimate_request = NULL): array {
    $this->estimateRequestId = (int) $estimate_request;

    $req = $this->loadEstimateRequest($this->estimateRequestId);

    // If already linked, inform and return to request.
    if ($req->hasField('field_contact') && !$req->get('field_contact')->isEmpty()) {
      $contact = $req->get('field_contact')->entity;
      $form['message'] = [
        '#markup' => '<p><strong>Contact is already linked.</strong></p>',
      ];
      if ($contact) {
        $form['message']['#markup'] .= '<p>Linked Contact: ' . $contact->toLink()->toString() . '</p>';
      }
      $form['actions'] = [
        '#type' => 'actions',
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('Back to Estimate Request'),
          '#url' => $this->getCancelUrl(),
          '#attributes' => ['class' => ['button']],
        ],
      ];
      return $form;
    }

    $preview = $this->extractRequestorPreview($req);

    $form['preview'] = [
      '#type' => 'item',
      '#title' => $this->t('Requestor info that will be used'),
      '#markup' => $this->buildPreviewMarkup($preview),
    ];

    // Duplicate detection preview.
    $dupes = $this->findPotentialDuplicates($preview['email'], $preview['phone_digits']);

    if (!empty($dupes)) {
      $items = [];
      foreach ($dupes as $contact) {
        $items[] = $contact->toLink()->toString();
      }

      $form['dupes'] = [
        '#type' => 'details',
        '#title' => $this->t('Potential duplicate Contacts found'),
        '#open' => TRUE,
        '#description' => $this->t('A match was found by email or phone. Confirm to link the first match.'),
      ];
      $form['dupes']['list'] = [
        '#theme' => 'item_list',
        '#items' => $items,
      ];
    }
    else {
      $form['dupes'] = [
        '#type' => 'item',
        '#title' => $this->t('Duplicate check'),
        '#markup' => '<p>No duplicates found by email or phone.</p>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $req = $this->loadEstimateRequest($this->estimateRequestId);

    // Idempotency: if a contact is already linked, do nothing.
    if ($req->hasField('field_contact') && !$req->get('field_contact')->isEmpty()) {
      $this->messenger()->addWarning($this->t('Contact is already linked; no changes made.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $preview = $this->extractRequestorPreview($req);

    // Require at least one stable identifier.
    if ($preview['email'] === '' && $preview['phone_digits'] === '' && $preview['name'] === '') {
      $this->messenger()->addError($this->t('No requestor information is available to create or match a Contact.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // Duplicate detection (email first, then phone).
    $dupes = $this->findPotentialDuplicates($preview['email'], $preview['phone_digits']);

    if (!empty($dupes)) {
      $contact = reset($dupes);
      $req->set('field_contact', ['target_id' => (int) $contact->id()]);
      $req->save();

      $this->logger->notice('Linked existing Contact @cid to Estimate Request @rid.', [
        '@cid' => $contact->id(),
        '@rid' => $req->id(),
      ]);

      $this->messenger()->addStatus($this->t('Linked existing Contact: @label', ['@label' => $contact->label()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // Create a new Contact.
    $contact_storage = $this->entityTypeManager->getStorage('contact');
    /** @var \Drupal\Core\Entity\EntityInterface $contact */
    $contact = $contact_storage->create([
      'type' => 'contact',
      'title' => $preview['name'] !== '' ? $preview['name'] : ('Contact for Request ' . $req->id()),
    ]);

    // Set fields only if they exist; never overwrite anything else later.
    if ($preview['name'] !== '' && $contact->hasField('field_name')) {
      $contact->set('field_name', $preview['name']);
    }
    if ($preview['email'] !== '' && $contact->hasField('field_email')) {
      $contact->set('field_email', $preview['email']);
    }
    if ($preview['phone_raw'] !== '' && $contact->hasField('field_phone')) {
      $contact->set('field_phone', $preview['phone_raw']);
    }
    if ($preview['address'] !== '' && $contact->hasField('field_address')) {
      $contact->set('field_address', $preview['address']);
    }

    try {
      $contact->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Contact creation failed from Estimate Request @rid: @msg', [
        '@rid' => $req->id(),
        '@msg' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Contact creation failed: @msg', ['@msg' => $e->getMessage()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // Link back to request.
    $req->set('field_contact', ['target_id' => (int) $contact->id()]);
    $req->save();

    $this->logger->notice('Created Contact @cid and linked to Estimate Request @rid.', [
      '@cid' => $contact->id(),
      '@rid' => $req->id(),
    ]);

    $this->messenger()->addStatus($this->t('Created and linked new Contact: @label', ['@label' => $contact->label()]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /* ------------------------------------------------------------------------ */
  /* Internal helpers                                                         */
  /* ------------------------------------------------------------------------ */

  private function loadEstimateRequest(int $id): \Drupal\Core\Entity\EntityInterface {
    $storage = $this->entityTypeManager->getStorage('estimate_request');
    $req = $storage->load($id);
    if (!$req) {
      throw new EstimateConversionException(sprintf('Estimate Request %d could not be loaded.', $id));
    }
    return $req;
  }

  private function extractRequestorPreview(\Drupal\Core\Entity\EntityInterface $req): array {
    $name = $req->hasField('field_requestor_name') ? trim((string) ($req->get('field_requestor_name')->value ?? '')) : '';
    $email_raw = $req->hasField('field_requestor_email') ? trim((string) ($req->get('field_requestor_email')->value ?? '')) : '';
    $email = $this->normalizeEmail($email_raw);

    $phone_raw = $req->hasField('field_requestor_phone') ? trim((string) ($req->get('field_requestor_phone')->value ?? '')) : '';
    $phone_digits = $this->normalizePhoneDigits($phone_raw);

    $address = $req->hasField('field_requestor_address') ? trim((string) ($req->get('field_requestor_address')->value ?? '')) : '';

    return [
      'name' => $name,
      'email' => $email,
      'phone_raw' => $phone_raw,
      'phone_digits' => $phone_digits,
      'address' => $address,
    ];
  }

  private function buildPreviewMarkup(array $p): string {
    $rows = [];
    $rows[] = '<tr><th>Name</th><td>' . htmlspecialchars($p['name'] ?: '-') . '</td></tr>';
    $rows[] = '<tr><th>Email</th><td>' . htmlspecialchars($p['email'] ?: '-') . '</td></tr>';
    $rows[] = '<tr><th>Phone</th><td>' . htmlspecialchars($p['phone_raw'] ?: '-') . '</td></tr>';
    $rows[] = '<tr><th>Address</th><td>' . htmlspecialchars($p['address'] ?: '-') . '</td></tr>';

    return '<table class="responsive-enabled"><tbody>' . implode('', $rows) . '</tbody></table>';
  }

  /**
   * Duplicate detection: email exact match OR phone exact match.
   *
   * Returns Contact entities (limited).
   */
  private function findPotentialDuplicates(string $normalized_email, string $phone_digits): array {
    $storage = $this->entityTypeManager->getStorage('contact');

    // Prefer email matches.
    if ($normalized_email !== '') {
      // Attempt common email field names.
      foreach (['field_email', 'mail', 'email'] as $email_field) {
        $ids = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', 1)
          ->condition($email_field, $normalized_email)
          ->range(0, 5)
          ->execute();

        if (!empty($ids)) {
          return $storage->loadMultiple($ids);
        }
      }
    }

    // Then phone matches (digits only compare).
    if ($phone_digits !== '') {
      // For phone we must scan because phone may be stored with formatting.
      // We keep this bounded to avoid heavy loads.
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->range(0, 100)
        ->execute();

      if (!empty($ids)) {
        $contacts = $storage->loadMultiple($ids);
        $matches = [];
        foreach ($contacts as $c) {
          foreach (['field_phone', 'phone'] as $phone_field) {
            if ($c->hasField($phone_field) && !$c->get($phone_field)->isEmpty()) {
              $candidate = $this->normalizePhoneDigits((string) ($c->get($phone_field)->value ?? ''));
              if ($candidate !== '' && $candidate === $phone_digits) {
                $matches[$c->id()] = $c;
              }
            }
          }
          if (count($matches) >= 5) {
            break;
          }
        }
        return array_values($matches);
      }
    }

    return [];
  }

  private function normalizeEmail(string $email): string {
    $email = trim(mb_strtolower($email));
    return $email;
  }

  private function normalizePhoneDigits(string $phone): string {
    return preg_replace('/\D+/', '', $phone) ?: '';
  }

}
