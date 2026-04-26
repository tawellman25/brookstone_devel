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
 * Office Manager review form: approve a flagged material price change.
 *
 * On confirm:
 * 1. Updates the matching material_suppliers row with the held-back price,
 *    the effective date, source = 'invoice' or 'wo_entry', and an audit
 *    note. Saving fires material.module's MAX-cost auto-sync, which
 *    propagates the new cost into material.field_cost_integer.
 * 2. Updates the material_price_history entry: status = 'approved',
 *    review_notes from form input, reviewed_by + reviewed_on stamps.
 * 3. Redirects to the review queue with a status message.
 */
final class PriceReviewApproveForm extends FormBase {

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
    return 'wo_material_price_sync_review_approve_form';
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

    if ($invoice === NULL) {
      $form['no_invoice_warning'] = [
        '#type' => 'inline_template',
        '#template' => '<div role="alert" style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px 16px;margin-bottom:1rem;font-size:0.9rem;"><strong>&#9888; This price change has no invoice number.</strong> Consider asking the crew member to confirm the source before approving.</div>',
        '#weight' => -10,
      ];
    }

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

    $form['review_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Review notes'),
      '#description' => $this->t('Briefly explain why you are approving this price change. Required.'),
      '#required' => TRUE,
      '#rows' => 3,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Approve and update catalog'),
      '#button_type' => 'primary',
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

    // Guard: only act on still-pending entries.
    $current_status = (string) $entry->get('field_status')->value;
    if (!in_array($current_status, ['flagged_high', 'auto_created'], TRUE)) {
      $this->messenger()->addWarning($this->t('This entry is not in a reviewable state (current: @s).', ['@s' => $current_status]));
      $form_state->setRedirect('view.material_price_review_queue.page_1');
      return;
    }

    $material_id = (int) $entry->get('field_material')->target_id;
    $supplier_id = (int) $entry->get('field_supplier')->target_id;
    $new_cost = (float) $entry->get('field_new_cost')->value;
    $invoice = $entry->hasField('field_supplier_invoice_number') && !$entry->get('field_supplier_invoice_number')->isEmpty()
      ? trim((string) $entry->get('field_supplier_invoice_number')->value)
      : NULL;
    $wo_id = $entry->hasField('field_wo_reference') && !$entry->get('field_wo_reference')->isEmpty()
      ? (int) $entry->get('field_wo_reference')->target_id
      : NULL;

    // Find the matching material_suppliers row.
    $ms_storage = $this->entityTypeManager->getStorage('material_suppliers');
    $ms_ids = $ms_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_material', $material_id)
      ->condition('field_supplier', $supplier_id)
      ->range(0, 1)
      ->execute();

    if (empty($ms_ids)) {
      $this->messenger()->addError($this->t('Matching material_suppliers row not found. Cannot apply the price change.'));
      $form_state->setRedirect('view.material_price_review_queue.page_1');
      return;
    }

    /** @var \Drupal\Core\Entity\EntityInterface $ms_row */
    $ms_row = $ms_storage->load(reset($ms_ids));
    $today = date('Y-m-d', $this->time->getRequestTime());
    $reviewer = $this->currentUser->getDisplayName();

    $existing_notes = '';
    if ($ms_row->hasField('field_price_notes') && !$ms_row->get('field_price_notes')->isEmpty()) {
      $existing_notes = trim((string) $ms_row->get('field_price_notes')->value);
    }
    $append = "Approved by {$reviewer} on {$today} from WO #{$wo_id} review";
    if ($invoice !== NULL) {
      $append .= " — invoice #{$invoice}";
    }
    $combined_notes = $existing_notes !== '' ? $existing_notes . "\n" . $append : $append;

    $ms_row->set('field_supplier_unit_cost', $new_cost);
    $ms_row->set('field_price_effective_date', $today);
    $ms_row->set('field_price_source', $invoice !== NULL ? 'invoice' : 'wo_entry');
    $ms_row->set('field_price_notes', $combined_notes);

    try {
      // Saving fires material.module's MAX-cost auto-sync.
      $ms_row->save();
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Failed to update supplier catalog: @msg', ['@msg' => $e->getMessage()]));
      $form_state->setRedirect('view.material_price_review_queue.page_1');
      return;
    }

    // Update the history entry.
    $entry->set('field_status', 'approved');
    $entry->set('field_review_notes', $review_notes);
    $entry->set('field_reviewed_by', (int) $this->currentUser->id());
    $entry->set('field_reviewed_on', date('Y-m-d\TH:i:s', $this->time->getRequestTime()));

    try {
      $entry->save();
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Catalog updated, but failed to update history entry: @msg', ['@msg' => $e->getMessage()]));
      $form_state->setRedirect('view.material_price_review_queue.page_1');
      return;
    }

    $this->messenger()->addStatus($this->t('Approved. Catalog price updated to $@cost.', ['@cost' => number_format($new_cost, 2)]));
    $form_state->setRedirect('view.material_price_review_queue.page_1');
  }

  /**
   * Returns the referenced entity's label, or a placeholder.
   */
  private function labelOf(EntityInterface $entry, string $field): string {
    if (!$entry->hasField($field) || $entry->get($field)->isEmpty()) {
      return '(unknown)';
    }
    $ref = $entry->get($field)->entity;
    return $ref ? (string) ($ref->label() ?? '(unknown)') : '(unknown)';
  }

}
