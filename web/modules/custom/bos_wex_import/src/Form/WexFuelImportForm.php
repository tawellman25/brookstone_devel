<?php

declare(strict_types=1);

namespace Drupal\bos_wex_import\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Upload form for WEX fuel transaction CSV/XLSX exports.
 *
 * On submit:
 *   1. Resolves the uploaded file's real path
 *   2. Parses + validates headers
 *   3. Builds a batch of one operation per row
 *   4. Hands off to Batch API; finished callback emits the summary
 *      and redirects to the master list.
 */
final class WexFuelImportForm extends FormBase {

  public function __construct(
    private readonly \Drupal\bos_wex_import\Service\WexFuelImportService $importService,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bos_wex_import.import_service'),
      $container->get('file_system'),
    );
  }

  public function getFormId(): string {
    return 'bos_wex_import_upload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => '<p>'
        . $this->t('Upload a CSV or XLSX export from the WEX online portal (Transaction Management Report). Each row becomes an %entity record. Drivers are resolved by Driver Prompt ID against teammate profiles; vehicles are resolved by Custom Vehicle/Asset ID against equipment numbers. Rows that fail to resolve are saved with a flag for manual review and will appear on the Review Queue tab.',
          ['%entity' => 'equipment_fuel_transaction'])
        . '</p>'
        . '<p><em>'
        . $this->t('Re-importing the same file is safe — transactions with WEX Transaction IDs that already exist in BOS are skipped silently.')
        . '</em></p>',
    ];

    $form['import_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('WEX Export File'),
      '#description' => $this->t('Accepts .csv, .xls, .xlsx. Maximum 10 MB.'),
      '#upload_location' => 'temporary://wex_import/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'csv xls xlsx'],
        'FileSizeLimit' => ['fileLimit' => 10 * 1024 * 1024],
      ],
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Import Transactions'),
        '#button_type' => 'primary',
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('view.equipment_fuel_transactions_admin.page_1'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fids = $form_state->getValue('import_file');
    if (empty($fids[0])) {
      $form_state->setErrorByName('import_file', $this->t('No file uploaded.'));
      return;
    }
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fids[0]);
    if (!$file) {
      $form_state->setErrorByName('import_file', $this->t('Uploaded file could not be loaded.'));
      return;
    }
    $real = $this->fileSystem->realpath($file->getFileUri());
    if (!$real || !is_readable($real)) {
      $form_state->setErrorByName('import_file', $this->t('Uploaded file is not readable.'));
      return;
    }

    try {
      $rows = $this->importService->parseFile($real);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('import_file', $this->t('Parse error: @msg', ['@msg' => $e->getMessage()]));
      return;
    }

    if (empty($rows)) {
      \Drupal::messenger()->addWarning($this->t('No data rows found in the uploaded file.'));
      return;
    }

    $headers = array_keys($rows[0]);
    $missing = $this->importService->validateHeaders($headers);
    if (!empty($missing)) {
      \Drupal::messenger()->addError($this->t(
        'Required column(s) missing from upload: @cols. Aborting import.',
        ['@cols' => implode(', ', $missing)]
      ));
      return;
    }

    $total = count($rows);
    $operations = [];
    $idx = 0;
    foreach ($rows as $row) {
      $idx++;
      $operations[] = [
        '\Drupal\bos_wex_import\Batch\WexFuelImportBatch::processRow',
        [$row, $idx, $total],
      ];
    }

    $batch = [
      'title' => $this->t('Importing @n WEX fuel transactions', ['@n' => $total]),
      'operations' => $operations,
      'finished' => '\Drupal\bos_wex_import\Batch\WexFuelImportBatch::finished',
      'init_message' => $this->t('Starting import of @n transactions…', ['@n' => $total]),
      'progress_message' => $this->t('Processed @current of @total transactions.'),
      'error_message' => $this->t('Import encountered an error.'),
    ];
    batch_set($batch);
  }

}
