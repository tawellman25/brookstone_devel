<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\file\FileRepositoryInterface;
use Drupal\supplier_price_ingest\Service\IngestMatcher;
use Drupal\supplier_price_ingest\Service\IngestParser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Upload a supplier price feed and kick off the parser.
 *
 * Phase 3.2 — parses the file inline (or via Batch API for large files)
 * and redirects to the placeholder batch view. Matching, dry-run UI,
 * and commit flow land in subsequent phases.
 *
 * Route: /admin/materials/supplier-ingest/upload
 * Permission: 'administer supplier price ingest'
 */
class BatchUploadForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
    protected IngestParser $parser,
    protected IngestMatcher $matcher,
    protected AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file.repository'),
      $container->get('supplier_price_ingest.parser'),
      $container->get('supplier_price_ingest.matcher'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'supplier_price_ingest_batch_upload';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;

    $supplierOptions = $this->buildSupplierOptions();
    if (empty($supplierOptions)) {
      $form['no_configs'] = [
        '#type' => 'markup',
        '#markup' => $this->t(
          'No suppliers have an active ingest configuration yet. <a href=":url">Add a supplier ingest config</a> first.',
          [':url' => Url::fromRoute('supplier_price_ingest.config_add')->toString()],
        ),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
      ];
      return $form;
    }

    $form['supplier'] = [
      '#type' => 'select',
      '#title' => $this->t('Supplier'),
      '#options' => $supplierOptions,
      '#required' => TRUE,
      '#empty_option' => $this->t('— Select supplier —'),
      '#description' => $this->t('Only suppliers with an active supplier_ingest_config appear here.'),
    ];

    $form['source_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Source file'),
      '#required' => TRUE,
      '#upload_location' => 'public://supplier_ingest/',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv xls xlsx'],
        'file_validate_size' => [50 * 1024 * 1024],
      ],
      '#description' => $this->t('CSV, XLS, or XLSX up to 50 MB. The supplier\'s column mapping (configured separately) governs how columns are interpreted.'),
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes (optional)'),
      '#rows' => 3,
      '#description' => $this->t('Free-text annotation for this batch — e.g. "April price list, includes new Rain Bird MP rotor pricing".'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload + parse'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $supplierId = (int) ($values['supplier'] ?? 0);
    $fileIds = $values['source_file'] ?? [];
    $notes = (string) ($values['notes'] ?? '');

    if (!$supplierId || empty($fileIds[0])) {
      $this->messenger()->addError($this->t('Missing supplier or source file.'));
      return;
    }

    $file = $this->entityTypeManager->getStorage('file')->load((int) $fileIds[0]);
    if (!$file) {
      $this->messenger()->addError($this->t('Uploaded file could not be loaded.'));
      return;
    }

    // Promote the file to permanent so it isn't garbage-collected.
    $file->setPermanent();
    $file->save();

    // Create the batch entity.
    $batch = $this->entityTypeManager->getStorage('supplier_price_ingest_batch')->create([
      'type' => 'batch',
      'title' => sprintf('Ingest %s — %s', $file->getFilename(), date('Y-m-d H:i')),
      'uid' => $this->currentUser->id(),
      'field_supplier' => $supplierId,
      'field_source_file' => $file->id(),
      'field_source_filename' => $file->getFilename(),
      'field_uploaded_by' => $this->currentUser->id(),
      'field_uploaded_on' => date('Y-m-d\TH:i:s'),
      'field_status' => 'pending_dry_run',
      'field_notes' => $notes,
    ]);
    $batch->save();

    // Decide sync vs Batch API based on row count.
    try {
      $rowCount = $this->parser->countSourceRows($batch);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t(
        'Could not pre-scan the file: @msg',
        ['@msg' => $e->getMessage()],
      ));
      $form_state->setRedirect('supplier_price_ingest.batch_view', ['supplier_price_ingest_batch' => $batch->id()]);
      return;
    }

    if ($rowCount <= IngestParser::SYNCHRONOUS_PARSE_THRESHOLD) {
      // Small file — parse + match inline.
      try {
        $parseResult = $this->parser->parseUploadedFile($batch);
        $this->messenger()->addStatus($this->t(
          'Batch @bid parsed: @summary',
          ['@bid' => $batch->id(), '@summary' => $parseResult->summary()],
        ));
      }
      catch (\Throwable $e) {
        $this->messenger()->addError($this->t(
          'Parse failed: @msg. Batch status is now "failed" — see batch detail.',
          ['@msg' => $e->getMessage()],
        ));
        $form_state->setRedirect('supplier_price_ingest.batch_view', ['supplier_price_ingest_batch' => $batch->id()]);
        return;
      }
      // Reload the batch fresh — parser may have changed status / counts.
      $batch = $this->entityTypeManager->getStorage('supplier_price_ingest_batch')->load($batch->id());
      if ((string) ($batch->get('field_status')->value ?? '') === 'pending_dry_run') {
        // Parse succeeded; run matcher.
        try {
          $matchResult = $this->matcher->matchBatch($batch);
          $this->messenger()->addStatus($this->t(
            'Batch @bid matched: @summary',
            ['@bid' => $batch->id(), '@summary' => $matchResult->summary()],
          ));
        }
        catch (\Throwable $e) {
          $this->messenger()->addError($this->t(
            'Match failed: @msg. Batch status is now "failed" — see batch detail.',
            ['@msg' => $e->getMessage()],
          ));
        }
      }
      $form_state->setRedirect('supplier_price_ingest.batch_view', ['supplier_price_ingest_batch' => $batch->id()]);
      return;
    }

    // Large file — Batch API. Parse + match in sequence. Each is its
    // own operation so progress reflects the current stage.
    $builder = (new BatchBuilder())
      ->setTitle($this->t('Processing @count rows from @file', [
        '@count' => $rowCount,
        '@file' => $file->getFilename(),
      ]))
      ->addOperation(
        [self::class, 'batchParseOperation'],
        [(int) $batch->id()],
      )
      ->addOperation(
        [self::class, 'batchMatchOperation'],
        [(int) $batch->id()],
      )
      ->setFinishCallback([self::class, 'batchFinishCallback']);
    batch_set($builder->toArray());
    $form_state->setRedirect('supplier_price_ingest.batch_view', ['supplier_price_ingest_batch' => $batch->id()]);
  }

  /**
   * Batch API operation — parse stage.
   *
   * The parser handles any row count internally without needing the
   * batch system to chunk it; we use Batch API here primarily to give
   * the user a progress page during long parses, not to actually slice
   * the work. If parses get genuinely slow (>30s), we'll chunk later.
   */
  public static function batchParseOperation(int $batchId, array &$context): void {
    $batch = \Drupal::entityTypeManager()->getStorage('supplier_price_ingest_batch')->load($batchId);
    if (!$batch) {
      $context['results']['parse_error'] = "Batch entity $batchId not loadable.";
      $context['results']['batch_id'] = $batchId;
      $context['finished'] = 1;
      return;
    }
    try {
      $result = \Drupal::service('supplier_price_ingest.parser')->parseUploadedFile($batch);
      $context['results']['parse_summary'] = $result->summary();
      $context['results']['batch_id'] = $batchId;
    }
    catch (\Throwable $e) {
      $context['results']['parse_error'] = $e->getMessage();
      $context['results']['batch_id'] = $batchId;
    }
    $context['finished'] = 1;
  }

  /**
   * Batch API operation — match stage. Runs after parse.
   *
   * Defensively re-loads the batch (parser may have set status='failed')
   * and skips if so.
   */
  public static function batchMatchOperation(int $batchId, array &$context): void {
    // If parse stage errored, don't run matcher.
    if (!empty($context['results']['parse_error'])) {
      $context['finished'] = 1;
      return;
    }
    $batch = \Drupal::entityTypeManager()->getStorage('supplier_price_ingest_batch')->load($batchId);
    if (!$batch) {
      $context['results']['match_error'] = "Batch entity $batchId not loadable after parse.";
      $context['finished'] = 1;
      return;
    }
    $status = (string) ($batch->get('field_status')->value ?? '');
    if ($status !== 'pending_dry_run') {
      $context['results']['match_error'] = "Batch $batchId status is '$status'; matcher only runs on 'pending_dry_run'.";
      $context['finished'] = 1;
      return;
    }
    try {
      $result = \Drupal::service('supplier_price_ingest.matcher')->matchBatch($batch);
      $context['results']['match_summary'] = $result->summary();
    }
    catch (\Throwable $e) {
      $context['results']['match_error'] = $e->getMessage();
    }
    $context['finished'] = 1;
  }

  /**
   * Batch API finish callback.
   */
  public static function batchFinishCallback(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(t('Batch run did not complete.'));
      return;
    }
    $bid = $results['batch_id'] ?? '?';
    if (isset($results['parse_error'])) {
      $messenger->addError(t('Batch @bid parse failed: @msg', ['@bid' => $bid, '@msg' => $results['parse_error']]));
      return;
    }
    if (isset($results['parse_summary'])) {
      $messenger->addStatus(t('Batch @bid parsed: @summary', ['@bid' => $bid, '@summary' => $results['parse_summary']]));
    }
    if (isset($results['match_error'])) {
      $messenger->addError(t('Batch @bid match failed: @msg', ['@bid' => $bid, '@msg' => $results['match_error']]));
      return;
    }
    if (isset($results['match_summary'])) {
      $messenger->addStatus(t('Batch @bid matched: @summary', ['@bid' => $bid, '@summary' => $results['match_summary']]));
    }
  }

  /**
   * Build the supplier options list — only suppliers that have an
   * active supplier_ingest_config.
   */
  private function buildSupplierOptions(): array {
    $configs = $this->entityTypeManager
      ->getStorage('supplier_ingest_config')
      ->loadByProperties(['field_active' => 1]);
    $options = [];
    foreach ($configs as $config) {
      $supplierId = (int) ($config->get('field_supplier')->target_id ?? 0);
      if (!$supplierId) {
        continue;
      }
      $supplier = $this->entityTypeManager->getStorage('supplier')->load($supplierId);
      if ($supplier) {
        $options[$supplierId] = $supplier->label();
      }
    }
    asort($options);
    return $options;
  }

}
