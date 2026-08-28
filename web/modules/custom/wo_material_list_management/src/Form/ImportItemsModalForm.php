<?php

declare(strict_types=1);

namespace Drupal\wo_material_list_management\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\eck\Entity\EckEntity;
use Drupal\wo_material_list_management\Service\MaterialListImportService;
use Drupal\wo_material_list_management\Service\InvoiceVisionExtractor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Two-step modal: upload/paste/photograph a material list → preview → import.
 */
final class ImportItemsModalForm extends FormBase {

  /**
   * Declared (not constructor-promoted) so DependencySerializationTrait can
   * re-inject when the cacheable form is serialized (managed_file).
   *
   * @var \Drupal\wo_material_list_management\Service\MaterialListImportService
   */
  protected $importer;

  /**
   * @var \Drupal\wo_material_list_management\Service\InvoiceVisionExtractor
   */
  protected $vision;

  public function __construct(MaterialListImportService $importer, InvoiceVisionExtractor $vision) {
    $this->importer = $importer;
    $this->vision = $vision;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wo_material_list_management.import'),
      $container->get('wo_material_list_management.invoice_vision'),
    );
  }

  public function getFormId(): string {
    return 'wo_material_list_import_items_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EckEntity $wo_material_list = NULL): array {
    if ($wo_material_list) {
      $form_state->set('list_id', (int) $wo_material_list->id());
    }

    $step = $form_state->get('step') ?? 'input';

    if ($step === 'preview') {
      return $this->buildPreviewStep($form, $form_state);
    }
    return $this->buildInputStep($form, $form_state);
  }

  private function buildInputStep(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#markup' => '<p>Upload a <strong>.csv</strong> / <strong>.xlsx</strong> or paste rows. Columns: '
        . '<em>identifier</em> (material ID or supplier item #), <em>quantity</em>, optional <em>unit cost</em>, optional <em>supplier</em>. '
        . 'A header row is auto-detected.</p>',
    ];
    $form['file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Spreadsheet file'),
      '#upload_location' => 'temporary://wo_material_import',
      '#upload_validators' => ['file_validate_extensions' => ['csv txt xls xlsx']],
    ];
    $form['paste'] = [
      '#type' => 'textarea',
      '#title' => $this->t('…or paste rows'),
      '#rows' => 6,
      '#placeholder' => "12345, 4\nSKU-778, 2, 19.50",
    ];
    // Photo intake — only when a vision provider (Claude/gpt-4o) is configured.
    if ($this->vision->isAvailable()) {
      $form['photo'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('…or snap a photo of a supplier invoice'),
        '#description' => $this->t('Upload/take a photo of a supplier invoice or ticket (JPG/PNG). AI reads the line items — item #, description, quantity, price — and you confirm every row in the next step. Photos can be angled or a little blurry.'),
        '#upload_location' => 'temporary://wo_material_import',
        '#upload_validators' => ['file_validate_extensions' => ['jpg jpeg png webp']],
      ];
    }
    $form['supplier'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'supplier',
      '#selection_handler' => 'default:supplier',
      '#title' => $this->t('Supplier (vendor this file is from)'),
      '#description' => $this->t('Optional — e.g. SiteOne. Lets BOS remember item numbers and update prices for that supplier.'),
    ];
    $form['learn'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remember new item numbers &amp; update prices for this supplier'),
      '#default_value' => TRUE,
      '#states' => ['visible' => [':input[name="supplier"]' => ['filled' => TRUE]]],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#submit' => ['::previewSubmit'],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromUri('internal:/wo_material_list/' . ((int) $form_state->get('list_id'))),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  private function buildPreviewStep(array $form, FormStateInterface $form_state): array {
    $rows = $form_state->get('rows') ?? [];
    $matched = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'matched'));
    $ambiguous = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'ambiguous'));
    $unmatched = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'unmatched'));

    $form['summary'] = [
      '#markup' => "<p><strong>" . count($rows) . "</strong> rows — {$matched} matched, {$ambiguous} to confirm, {$unmatched} unmatched. "
        . "Adjust below, then import. Unmatched rows: pick a material or leave unchecked to skip.</p>",
    ];

    // Vision-extraction context: vendor guess + case/return warnings.
    $vendor = $form_state->get('extract_vendor');
    if ($vendor) {
      $form['extract_vendor'] = [
        '#markup' => '<p>Read from a <strong>photo</strong> — vendor: <strong>' . htmlspecialchars($vendor)
          . '</strong>. Double-check the item numbers, quantities, and prices below before importing.</p>',
      ];
    }
    $warnings = $form_state->get('extract_warnings') ?? [];
    if ($warnings) {
      $form['extract_warnings'] = [
        '#markup' => '<div class="messages messages--warning"><ul><li>'
          . implode('</li><li>', array_map('htmlspecialchars', $warnings)) . '</li></ul></div>',
      ];
    }

    $sid = $form_state->get('supplier_id');
    if ($sid && ($sup = \Drupal::entityTypeManager()->getStorage('supplier')->load($sid))) {
      $form['supplier_note'] = [
        '#markup' => '<p><strong>Supplier:</strong> ' . htmlspecialchars($sup->label())
          . ($form_state->get('learn') ? ' — new item numbers &amp; prices will be remembered for this supplier.' : '') . '</p>',
      ];
    }

    $form['rows'] = ['#type' => 'table', '#header' => ['Include', 'Item #', 'Description', 'Status', 'Material', 'Qty', 'Unit cost']];
    foreach ($rows as $i => $r) {
      $status = $r['status'] ?? 'unmatched';
      $form['rows'][$i]['include'] = [
        '#type' => 'checkbox',
        '#default_value' => ($status !== 'unmatched'),
      ];
      $form['rows'][$i]['identifier'] = ['#markup' => '<code>' . htmlspecialchars($r['identifier']) . '</code>'];
      $form['rows'][$i]['description'] = ['#markup' => '<span class="wo-import-desc">' . htmlspecialchars($r['description'] ?? '') . '</span>'];
      $form['rows'][$i]['status'] = ['#markup' => '<span class="wo-import-status wo-import-status--' . $status . '">' . ucfirst($status) . '</span>'];
      $default_material = !empty($r['material_id'])
        ? \Drupal::entityTypeManager()->getStorage('material')->load($r['material_id'])
        : NULL;
      $form['rows'][$i]['material'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'material',
        '#default_value' => $default_material,
        '#selection_handler' => 'default:material',
      ];
      $form['rows'][$i]['quantity'] = [
        '#type' => 'number', '#min' => 1, '#default_value' => is_numeric($r['quantity']) ? (int) $r['quantity'] : 1, '#size' => 5,
      ];
      $form['rows'][$i]['unit_cost'] = [
        '#type' => 'textfield', '#default_value' => $r['unit_cost'] ?? '', '#size' => 8, '#placeholder' => 'catalog',
      ];
      $form['rows'][$i]['supplier_id'] = ['#type' => 'value', '#value' => $r['supplier_id'] ?? NULL];
      $form['rows'][$i]['supplier_item_number'] = ['#type' => 'value', '#value' => $r['supplier_item_number'] ?? ($r['identifier'] ?? '')];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import items'),
      '#button_type' => 'primary',
    ];
    $form['actions']['restart'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start over'),
      '#submit' => ['::restartSubmit'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * Parse + match, move to preview.
   */
  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    $raw = [];
    $form_state->set('extract_warnings', NULL);
    $form_state->set('extract_vendor', NULL);
    $pids = $form_state->getValue('photo');
    $fids = $form_state->getValue('file');
    if (!empty($pids[0]) && ($img = \Drupal::entityTypeManager()->getStorage('file')->load($pids[0]))) {
      // Photo → vision extraction → rows (identifier/description/quantity/unit_cost).
      $path = \Drupal::service('file_system')->realpath($img->getFileUri());
      $binary = $path ? (string) file_get_contents($path) : '';
      try {
        $ex = $this->vision->extract($binary, (string) $img->getMimeType());
      }
      catch (\Throwable $e) {
        $this->messenger()->addError($this->t('Could not read the invoice photo: @m', ['@m' => $e->getMessage()]));
        $form_state->setRebuild(TRUE);
        return;
      }
      $raw = $ex['rows'];
      $form_state->set('extract_vendor', $ex['vendor']);
      $form_state->set('extract_warnings', $ex['warnings']);
    }
    elseif (!empty($fids[0]) && ($file = \Drupal::entityTypeManager()->getStorage('file')->load($fids[0]))) {
      $raw = $this->importer->parseFile($file);
    }
    elseif (trim((string) $form_state->getValue('paste')) !== '') {
      $raw = $this->importer->parsePaste((string) $form_state->getValue('paste'));
    }
    if (!$raw) {
      $this->messenger()->addWarning($this->t('No rows found — upload a file or paste some rows.'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $form_state->set('supplier_id', $form_state->getValue('supplier') ? (int) $form_state->getValue('supplier') : NULL);
    $form_state->set('learn', (bool) $form_state->getValue('learn'));
    $form_state->set('rows', $this->importer->matchRows($raw));
    $form_state->set('step', 'preview');
    $form_state->setRebuild(TRUE);
  }

  public function restartSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', 'input');
    $form_state->set('rows', NULL);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Create/merge the confirmed rows, learn supplier links, back to the list.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $listId = (int) $form_state->get('list_id');
    $submitted = $form_state->getValue('rows') ?? [];
    $rows = [];
    foreach ($submitted as $r) {
      $rows[] = [
        'include' => !empty($r['include']),
        'material_id' => (int) ($r['material'] ?? 0),
        'quantity' => (int) ($r['quantity'] ?? 1),
        'unit_cost' => $r['unit_cost'] ?? '',
        'supplier_id' => $r['supplier_id'] ?? NULL,
        'supplier_item_number' => $r['supplier_item_number'] ?? '',
      ];
    }
    $supplierId = $form_state->get('supplier_id');
    $learn = (bool) $form_state->get('learn');
    $result = $this->importer->import($listId, $rows, $supplierId, $learn);
    $this->messenger()->addStatus($this->t('Imported @c new, merged @m, skipped @s.', [
      '@c' => $result['created'], '@m' => $result['merged'], '@s' => $result['skipped'],
    ]));
    if (!empty($result['links_created']) || !empty($result['links_updated'])) {
      $this->messenger()->addStatus($this->t('Catalog updated: @lc item number(s) remembered, @lu price(s) updated for the supplier.', [
        '@lc' => $result['links_created'], '@lu' => $result['links_updated'],
      ]));
    }
    $form_state->setRedirectUrl(Url::fromUri('internal:/wo_material_list/' . $listId));
  }

}
