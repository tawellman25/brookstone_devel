<?php

declare(strict_types=1);

namespace Drupal\wo_material_list_management\Form;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\eck\Entity\EckEntity;
use Drupal\file\FileInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\wo_material_list_management\Service\MaterialListImportService;
use Drupal\wo_material_list_management\Service\InvoiceVisionExtractor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Two-step form: photograph (or upload/paste) a material list → preview → import.
 *
 * Photo first. The receipt photo is the primary path for a crew member on a
 * phone; the spreadsheet / pasted rows are the office's secondary path and sit
 * in a collapsed section.
 *
 * The uploads are plain `file` elements ON PURPOSE, not `managed_file`. A
 * managed_file pre-uploads by AJAX the moment a file is picked, and core's
 * file.js (`Drupal.file.disableFields`, bound to `mousedown` on every submit
 * button) DISABLES every managed-file input that does not belong to the button
 * pressed — a disabled input is not sent with the form. So a crew member who
 * taps Preview while a phone photo is still pre-uploading over cellular posts a
 * form with no photo in it and gets "No rows found" (Todd, 2026-11-13, Delta
 * Hardware receipt on WO 54616). A plain file input rides along with the
 * Preview submit itself: one tap, one POST, and validateForm() saves it through
 * the file.upload_handler service with the same extension rules.
 */
final class ImportItemsModalForm extends FormBase {

  /**
   * Declared (not constructor-promoted) so DependencySerializationTrait can
   * re-inject when the cacheable multi-step form is serialized.
   *
   * @var \Drupal\wo_material_list_management\Service\MaterialListImportService
   */
  protected $importer;

  /**
   * @var \Drupal\wo_material_list_management\Service\InvoiceVisionExtractor
   */
  protected $vision;

  /**
   * @var \Drupal\file\Upload\FileUploadHandlerInterface
   */
  protected $uploadHandler;

  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Where both uploads land (temporary files; cron sweeps them).
   */
  private const UPLOAD_DIR = 'temporary://wo_material_import';

  public function __construct(MaterialListImportService $importer, InvoiceVisionExtractor $vision, FileUploadHandlerInterface $upload_handler, FileSystemInterface $file_system) {
    $this->importer = $importer;
    $this->vision = $vision;
    $this->uploadHandler = $upload_handler;
    $this->fileSystem = $file_system;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wo_material_list_management.import'),
      $container->get('wo_material_list_management.invoice_vision'),
      $container->get('file.upload_handler'),
      $container->get('file_system'),
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
    $vision = $this->vision->isAvailable();

    // ── Primary: a photo of the receipt / invoice ─────────────────────────
    if ($vision) {
      $form['photo_wrap'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['wo-import-photo']],
      ];
      $form['photo_wrap']['intro'] = [
        '#markup' => '<p><strong>' . $this->t('Snap a photo of the receipt or invoice.') . '</strong> '
          . $this->t('BOS reads the item numbers, descriptions, quantities and prices, and you confirm every row before anything is added to the list.') . '</p>',
      ];
      $form['photo_wrap']['photo'] = [
        '#type' => 'file',
        '#title' => $this->t('Receipt / invoice photo'),
        '#description' => $this->t('Take a photo now or pick one from your photos (JPG, PNG or WebP). Angled or a little blurry is fine — just make sure the item lines are readable.'),
        // image/* lets a phone offer Camera and Photos; no `capture`, which
        // would force the camera and hide an already-taken photo.
        '#attributes' => ['accept' => 'image/*'],
        '#parents' => ['photo'],
      ];
    }

    $form['supplier'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'supplier',
      '#selection_handler' => 'default:supplier',
      '#title' => $this->t('Supplier (who the receipt or file is from)'),
      '#description' => $this->t('Optional — e.g. Delta Hardware, SiteOne. Lets BOS remember item numbers and update prices for that supplier.'),
    ];
    $form['learn'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remember new item numbers &amp; update prices for this supplier'),
      '#default_value' => TRUE,
      '#states' => ['visible' => [':input[name="supplier"]' => ['filled' => TRUE]]],
    ];

    // ── Secondary: spreadsheet file or pasted rows ────────────────────────
    $form['other'] = [
      '#type' => 'details',
      '#title' => $vision
        ? $this->t('Or import a spreadsheet / paste rows instead')
        : $this->t('Spreadsheet file or pasted rows'),
      '#open' => !$vision,
    ];
    $form['other']['help'] = [
      '#markup' => '<p>' . $this->t('Upload a <strong>.csv</strong> / <strong>.xlsx</strong> or paste rows. Columns: <em>identifier</em> (material ID or supplier item #), <em>quantity</em>, optional <em>unit cost</em>, optional <em>supplier</em>. A header row is auto-detected.') . '</p>',
    ];
    $form['other']['file'] = [
      '#type' => 'file',
      '#title' => $this->t('Spreadsheet file'),
      '#attributes' => ['accept' => '.csv,.txt,.xls,.xlsx'],
      '#parents' => ['file'],
    ];
    $form['other']['paste'] = [
      '#type' => 'textarea',
      '#title' => $this->t('…or paste rows'),
      '#rows' => 6,
      '#placeholder' => "12345, 4\nSKU-778, 2, 19.50",
      '#parents' => ['paste'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#button_type' => 'primary',
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

  /**
   * Input step only: save the plain-file uploads and insist on some input.
   *
   * Runs in the same request as the Preview submit, so the browser's file is
   * still in the request. The saved temporary File ids are stashed on the form
   * state for previewSubmit().
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->get('step') ?? 'input') !== 'input') {
      return;
    }
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#parents'][0] ?? '') !== 'preview') {
      return;
    }
    $form_state->set('upload_photo', NULL);
    $form_state->set('upload_file', NULL);
    $vision = $this->vision->isAvailable();
    if ($vision && ($photo = $this->saveUpload('photo', 'jpg jpeg png webp', $form_state))) {
      $form_state->set('upload_photo', (int) $photo->id());
    }
    if ($sheet = $this->saveUpload('file', 'csv txt xls xlsx', $form_state)) {
      $form_state->set('upload_file', (int) $sheet->id());
    }
    if ($form_state->getErrors()) {
      return;
    }
    if (!$form_state->get('upload_photo') && !$form_state->get('upload_file') && trim((string) $form_state->getValue('paste')) === '') {
      $form_state->setErrorByName($vision ? 'photo' : 'file', $vision
        ? $this->t('Add a photo of the receipt — or open the spreadsheet section and upload a file or paste rows — then tap Preview.')
        : $this->t('Upload a spreadsheet or paste some rows, then press Preview.'));
    }
  }

  /**
   * Save one plain `file` upload as a temporary managed file, or NULL.
   *
   * Extension violations and write failures become form errors on $key.
   */
  private function saveUpload(string $key, string $extensions, FormStateInterface $form_state): ?FileInterface {
    $uploaded = $this->getRequest()->files->get('files', [])[$key] ?? NULL;
    // Symfony hands NULL for an untouched file input (UPLOAD_ERR_NO_FILE).
    if (!$uploaded instanceof UploadedFile) {
      return NULL;
    }
    $dir = self::UPLOAD_DIR;
    if (!$this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $form_state->setErrorByName($key, $this->t('The upload folder is not writable — tell the office.'));
      return NULL;
    }
    try {
      $result = $this->uploadHandler->handleFileUpload(
        new FormUploadedFile($uploaded),
        ['FileExtension' => ['extensions' => $extensions]],
        $dir,
        FileExists::Rename,
      );
    }
    catch (\Throwable $e) {
      $form_state->setErrorByName($key, $this->t('The upload could not be saved: @m', ['@m' => $e->getMessage()]));
      return NULL;
    }
    if ($result->hasViolations()) {
      foreach ($result->getViolations() as $violation) {
        $form_state->setErrorByName($key, (string) $violation->getMessage());
      }
      return NULL;
    }
    return $result->getFile();
  }

  private function buildPreviewStep(array $form, FormStateInterface $form_state): array {
    $rows = $form_state->get('rows') ?? [];
    $matched = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'matched'));
    $ambiguous = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'ambiguous'));
    $unmatched = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'unmatched'));

    $form['summary'] = [
      '#markup' => "<p><strong>" . count($rows) . "</strong> rows — {$matched} in the catalog, {$ambiguous} possible match, {$unmatched} not in the catalog. "
        . "<strong>Only catalog matches are pre-checked.</strong> Tick any other row you want to bring in. "
        . "For a possible match, confirm or fix the Material. Anything <strong>not in the catalog imports as a Purchased line</strong> — "
        . "the receipt's name, quantity and cost, not tied to a catalog material — as long as the Material box stays empty. "
        . "The import never creates catalog materials.</p>",
    ];

    if ($rid = (int) ($form_state->get('receipt_fid') ?? 0)) {
      $rf = \Drupal::entityTypeManager()->getStorage('file')->load($rid);
      if ($rf) {
        $form['receipt_note'] = [
          '#markup' => '<p class="wo-import-receipt-note">📷 <strong>' . $this->t('Receipt photo') . '</strong> <em>' . htmlspecialchars($rf->getFilename())
            . '</em> — ' . $this->t('attached to every row you import (one photo, shared by all of its rows).') . '</p>',
        ];
      }
    }

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
      // Only a CONFIDENT exact match (material ID or a known supplier item
      // number) is pre-checked. Fuzzy "ambiguous" title guesses and unmatched
      // rows default OFF so a wrong guess never imports itself — the crew ticks
      // the ones they actually want (matching, or bringing in as purchased).
      $form['rows'][$i]['include'] = [
        '#type' => 'checkbox',
        '#default_value' => ($status === 'matched'),
      ];
      $form['rows'][$i]['identifier'] = ['#markup' => '<code>' . htmlspecialchars($r['identifier']) . '</code>'];
      $form['rows'][$i]['description'] = ['#markup' => '<span class="wo-import-desc">' . htmlspecialchars($r['description'] ?? '') . '</span>'];
      $status_label = match ($status) {
        'matched' => $this->t('In catalog'),
        'ambiguous' => $this->t('Possible match'),
        default => $this->t('Not in catalog → Purchased'),
      };
      $form['rows'][$i]['status'] = ['#markup' => '<span class="wo-import-status wo-import-status--' . $status . '">' . $status_label . '</span>'];
      $default_material = !empty($r['material_id'])
        ? \Drupal::entityTypeManager()->getStorage('material')->load($r['material_id'])
        : NULL;
      $form['rows'][$i]['material'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'material',
        '#default_value' => $default_material,
        '#selection_handler' => 'default:material',
        '#placeholder' => $this->t('empty = purchased item'),
      ];
      $form['rows'][$i]['quantity'] = [
        '#type' => 'number', '#min' => 1, '#default_value' => is_numeric($r['quantity']) ? (int) $r['quantity'] : 1, '#size' => 5,
      ];
      $form['rows'][$i]['unit_cost'] = [
        '#type' => 'textfield', '#default_value' => $r['unit_cost'] ?? '', '#size' => 8, '#placeholder' => 'catalog',
      ];
      $form['rows'][$i]['supplier_id'] = ['#type' => 'value', '#value' => $r['supplier_id'] ?? NULL];
      $form['rows'][$i]['supplier_item_number'] = ['#type' => 'value', '#value' => $r['supplier_item_number'] ?? ($r['identifier'] ?? '')];
      // Carried through so an unmatched/cleared row can be imported as a
      // purchased free-text line (name = description, else identifier).
      $form['rows'][$i]['identifier_raw'] = ['#type' => 'value', '#value' => $r['identifier'] ?? ''];
      $form['rows'][$i]['description_raw'] = ['#type' => 'value', '#value' => $r['description'] ?? ''];
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
    $form_state->set('receipt_fid', NULL);
    $pid = (int) ($form_state->get('upload_photo') ?? 0);
    $fid = (int) ($form_state->get('upload_file') ?? 0);
    if ($pid && ($img = \Drupal::entityTypeManager()->getStorage('file')->load($pid))) {
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
      // The photo travels with the rows: attached to every imported line.
      $form_state->set('receipt_fid', $pid);
    }
    elseif ($fid && ($file = \Drupal::entityTypeManager()->getStorage('file')->load($fid))) {
      $raw = $this->importer->parseFile($file);
    }
    elseif (trim((string) $form_state->getValue('paste')) !== '') {
      $raw = $this->importer->parsePaste((string) $form_state->getValue('paste'));
    }
    if (!$raw) {
      $this->messenger()->addWarning($pid
        ? $this->t('No line items could be read from that photo. Try a closer, straighter shot of the item lines — or open the spreadsheet section and paste the rows.')
        : $this->t('No rows found in that file or text — check the columns (identifier, quantity, unit cost) and try again.'));
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
    $form_state->set('receipt_fid', NULL);
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
        'description' => $r['description_raw'] ?? '',
        'identifier' => $r['identifier_raw'] ?? '',
      ];
    }
    $supplierId = $form_state->get('supplier_id');
    $learn = (bool) $form_state->get('learn');
    $receiptFid = (int) ($form_state->get('receipt_fid') ?? 0) ?: NULL;
    $result = $this->importer->import($listId, $rows, $supplierId, $learn, $receiptFid);
    $this->messenger()->addStatus($this->t('Imported @c new, merged @m, skipped @s.', [
      '@c' => $result['created'], '@m' => $result['merged'], '@s' => $result['skipped'],
    ]));
    if ($receiptFid) {
      $n = (int) ($result['receipt_attached'] ?? 0);
      if ($n > 0) {
        $this->messenger()->addStatus($this->t('Receipt photo attached to @n row(s).', ['@n' => $n]));
      }
      else {
        $this->messenger()->addWarning($this->t("The receipt photo could not be attached to the rows — the office can add it from each item's edit form."));
      }
    }
    if (!empty($result['links_created']) || !empty($result['links_updated'])) {
      $this->messenger()->addStatus($this->t('Catalog updated: @lc item number(s) remembered, @lu price(s) updated for the supplier.', [
        '@lc' => $result['links_created'], '@lu' => $result['links_updated'],
      ]));
    }
    $form_state->setRedirectUrl(Url::fromUri('internal:/wo_material_list/' . $listId));
  }

}
