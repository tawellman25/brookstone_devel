<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phase 3.7 — kick a fuzzy_med row back to the discovery queue when
 * the proposed match isn't right AND the correct match doesn't exist
 * in BOS yet.
 *
 * Route: /admin/materials/supplier-ingest/fuzzy-review/{row}/send-to-discovery
 *
 * Transitions:
 *   field_match_tier:   tier_3_fuzzy_med → discovery
 *   field_matched_material: cleared
 *   field_row_status:   stays 'discovery_pending'
 *
 * After save the row vanishes from the Fuzzy Match Review view
 * (which filters on tier=tier_3_fuzzy_med) and appears in the
 * Discovery Queue view (which filters on tier=discovery). The
 * shared discovery_pending status keeps the row in "needs review"
 * state across the move.
 */
class SendToDiscoveryForm extends FormBase {

  use IngestRowFormTrait;

  private ?EntityInterface $row = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'supplier_price_ingest_send_to_discovery';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_row = NULL): array {
    $this->row = $supplier_price_ingest_row;
    if (!$this->row) {
      $form['error'] = ['#markup' => $this->t('No row loaded.')];
      return $form;
    }

    $form['back_link'] = $this->buildBackToQueueLink(self::CTX_FUZZY_REVIEW);
    $this->attachRowFormLibrary($form);

    $form['row_summary'] = $this->buildRowSummary($this->row);
    $form['explainer'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages messages--status">' . $this->t('Send this row to the Discovery Queue. Use this when the proposed fuzzy match is wrong AND the correct material does not yet exist in BOS — the reviewer there can create a new material from the row.') . '</div>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send to Discovery Queue'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromUserInput('/admin/materials/supplier-ingest/fuzzy-review'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $row = $this->row;
    if (!$row) {
      return;
    }
    $row->set('field_match_tier', 'discovery');
    $row->set('field_matched_material', NULL);
    // field_row_status stays 'discovery_pending' — same review surface,
    // different queue.
    $this->appendNote($row, sprintf('Sent to discovery queue by %s — proposed fuzzy match rejected, correct material does not exist in BOS.', $this->currentUser->getDisplayName()));
    $row->save();

    $this->messenger()->addStatus($this->t('Row sent to Discovery Queue.'));
    $form_state->setRedirectUrl($this->nextRowRedirect(
      $this->row,
      'supplier_price_ingest.fuzzy_send_to_discovery',
      self::CTX_FUZZY_REVIEW,
      $this->entityTypeManager,
      $this->messenger(),
    ));
  }

}
