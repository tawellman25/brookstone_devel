<?php

declare(strict_types=1);

namespace Drupal\wo_material_price_sync\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Office Manager review form: reject a flagged material price change.
 *
 * On confirm:
 * 1. Does NOT touch material_suppliers — original price preserved.
 * 2. Updates the material_price_history entry: status = 'rejected',
 *    review_notes from form input, reviewed_by + reviewed_on stamps.
 * 3. Redirects to the review queue with a status message.
 */
final class PriceReviewRejectForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('datetime.time'),
    );
  }

  public function getFormId(): string {
    return 'wo_material_price_sync_review_reject_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $material_price_history = NULL): array {
    if (!$material_price_history) {
      throw new \InvalidArgumentException('material_price_history entity required.');
    }

    $entry = $material_price_history;
    $material_label = $this->labelOf($entry, 'field_material');
    $supplier_label = $this->labelOf($entry, 'field_supplier');
    $invoice = $entry->hasField('field_supplier_invoice_number') && !$entry->get('field_supplier_invoice_number')->isEmpty()
      ? trim((string) $entry->get('field_supplier_invoice_number')->value)
      : NULL;
    $old_cost = $entry->hasField('field_old_cost') && !$entry->get('field_old_cost')->isEmpty()
      ? (float) $entry->get('field_old_cost')->value
      : NULL;
    $new_cost = $entry->hasField('field_new_cost') && !$entry->get('field_new_cost')->isEmpty()
      ? (float) $entry->get('field_new_cost')->value
      : NULL;
    $delta_pct = $entry->hasField('field_delta_percent') && !$entry->get('field_delta_percent')->isEmpty()
      ? (float) $entry->get('field_delta_percent')->value
      : NULL;

    $summary_lines = [];
    $summary_lines[] = '<strong>Material:</strong> ' . htmlspecialchars($material_label);
    $summary_lines[] = '<strong>Supplier:</strong> ' . htmlspecialchars($supplier_label);
    $summary_lines[] = '<strong>Invoice #:</strong> ' . ($invoice !== NULL ? htmlspecialchars($invoice) : '<em>(none provided)</em>');
    if ($old_cost !== NULL) {
      $summary_lines[] = '<strong>Old Cost:</strong> $' . number_format($old_cost, 2);
    }
    if ($new_cost !== NULL) {
      $summary_lines[] = '<strong>New Cost:</strong> $' . number_format($new_cost, 2);
    }
    if ($delta_pct !== NULL) {
      $sign = $delta_pct >= 0 ? '+' : '';
      $summary_lines[] = '<strong>Delta:</strong> ' . $sign . number_format($delta_pct, 1) . '%';
    }

    $form['summary'] = [
      '#type' => 'inline_template',
      '#template' => '<div style="background:#f8f9fa;border-radius:6px;padding:14px 18px;margin-bottom:1rem;line-height:1.7;">{% for line in lines %}<div>{{ line|raw }}</div>{% endfor %}</div>',
      '#context' => ['lines' => $summary_lines],
    ];

    $form['notice'] = [
      '#type' => 'inline_template',
      '#template' => '<p style="font-size:0.9rem;color:#495057;"><em>Rejecting will preserve the supplier catalog\'s original price. The history entry will be marked as rejected with your notes.</em></p>',
    ];

    $form['review_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Review notes'),
      '#description' => $this->t('Briefly explain why you are rejecting this price change. Required.'),
      '#required' => TRUE,
      '#rows' => 3,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject'),
      '#button_type' => 'danger',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('view.material_price_review_queue.page_1'),
      '#attributes' => ['class' => ['button']],
    ];

    $form_state->set('history_entry_id', (int) $entry->id());

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entry_id = (int) $form_state->get('history_entry_id');
    $review_notes = trim((string) $form_state->getValue('review_notes'));

    /** @var \Drupal\Core\Entity\EntityInterface|null $entry */
    $entry = $this->entityTypeManager->getStorage('material_price_history')->load($entry_id);
    if (!$entry) {
      $this->messenger()->addError($this->t('Price history entry not found.'));
      $form_state->setRedirect('view.material_price_review_queue.page_1');
      return;
    }

    $current_status = (string) $entry->get('field_status')->value;
    if (!in_array($current_status, ['flagged_high', 'auto_created'], TRUE)) {
      $this->messenger()->addWarning($this->t('This entry is not in a reviewable state (current: @s).', ['@s' => $current_status]));
      $form_state->setRedirect('view.material_price_review_queue.page_1');
      return;
    }

    // DO NOT touch material_suppliers. Just record the rejection.
    $entry->set('field_status', 'rejected');
    $entry->set('field_review_notes', $review_notes);
    $entry->set('field_reviewed_by', (int) $this->currentUser->id());
    $entry->set('field_reviewed_on', date('Y-m-d\TH:i:s', $this->time->getRequestTime()));

    try {
      $entry->save();
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Failed to update history entry: @msg', ['@msg' => $e->getMessage()]));
      $form_state->setRedirect('view.material_price_review_queue.page_1');
      return;
    }

    $this->messenger()->addStatus($this->t('Rejected. No catalog change made. Original price preserved.'));
    $form_state->setRedirect('view.material_price_review_queue.page_1');
  }

  private function labelOf(EntityInterface $entry, string $field): string {
    if (!$entry->hasField($field) || $entry->get($field)->isEmpty()) {
      return '(unknown)';
    }
    $ref = $entry->get($field)->entity;
    return $ref ? (string) ($ref->label() ?? '(unknown)') : '(unknown)';
  }

}
