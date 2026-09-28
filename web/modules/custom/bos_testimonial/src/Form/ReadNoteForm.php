<?php

declare(strict_types=1);

namespace Drupal\bos_testimonial\Form;

use Drupal\bos_testimonial\Service\NoteRedactor;
use Drupal\bos_testimonial\Service\NoteVisionExtractor;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read a photographed handwritten note into a testimonial.
 *
 * Full page, not a modal: it is a multi-step review with an image to actually
 * look at, and modals make that cramped (the lesson from the material-list
 * import).
 *
 * The model drafts, the office decides. Nothing is written to the testimonial,
 * and above all nothing reaches the public image field, until someone has read
 * the transcript against the photo and looked at where the blur landed.
 *
 * The split of labour is deliberate and was settled by testing rather than
 * assumption. Claude transcribes a note accurately and reliably says WHAT
 * personal detail is on it. It is poor at saying WHERE: on a 700px test note it
 * placed the signature ~190px too high, which would have blurred a harmless
 * sentence and published the signature under a redacted-looking image. So the
 * model's findings are a checklist, and the office sets the blur line itself
 * while watching the preview.
 */
final class ReadNoteForm extends FormBase {

  /**
   * Injected services.
   *
   * Declared, NOT constructor-promoted: this form rebuilds, so its state is
   * serialised, and DependencySerializationTrait cannot re-inject promoted
   * readonly properties on the way back in.
   */
  protected NoteVisionExtractor $vision;
  protected NoteRedactor $redactor;
  protected EntityTypeManagerInterface $etm;
  protected FileSystemInterface $files;

  public static function create(ContainerInterface $container): self {
    $form = new self();
    $form->vision = $container->get('bos_testimonial.note_vision');
    $form->redactor = $container->get('bos_testimonial.note_redactor');
    $form->etm = $container->get('entity_type.manager');
    $form->files = $container->get('file_system');
    return $form;
  }

  public function getFormId(): string {
    return 'bos_testimonial_read_note';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $testimonial = NULL): array {
    $testimonial = $testimonial ?: $form_state->get('testimonial');
    $form_state->set('testimonial', $testimonial);

    if (!$this->vision->isAvailable()) {
      $form['none'] = [
        '#markup' => '<p>' . $this->t('No vision provider is configured, so notes cannot be read automatically. Type the words in by hand on the testimonial form.') . '</p>',
      ];
      return $form;
    }

    $result = $form_state->get('result');
    return $result ? $this->buildReviewStep($form, $form_state, $testimonial, $result)
      : $this->buildPickStep($form, $form_state, $testimonial);
  }

  /**
   * Step 1 — choose which scan to read.
   */
  private function buildPickStep(array $form, FormStateInterface $form_state, EntityInterface $testimonial): array {
    $options = [];
    foreach ($testimonial->get('field_testimonial_scan') as $delta => $item) {
      $file = $item->entity;
      if (!$file) {
        continue;
      }
      $options[$delta] = $file->getFilename() . ' (' . $file->getMimeType() . ')';
    }

    if (!$options) {
      $form['none'] = [
        '#markup' => '<p>' . $this->t('This testimonial has no scan attached yet. Add a photo of the note to "Scan of the original note" first.') . '</p>',
      ];
      $form['back'] = $this->backLink($testimonial);
      return $form;
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('The model will draft a transcript and propose where to blur personal details. You will see both before anything is saved.') . '</p>',
    ];
    $form['delta'] = [
      '#type' => 'radios',
      '#title' => $this->t('Which scan?'),
      '#options' => $options,
      '#default_value' => array_key_first($options),
      '#access' => count($options) > 1,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['read'] = [
      '#type' => 'submit',
      '#value' => $this->t('Read the note'),
      '#button_type' => 'primary',
      '#submit' => ['::readSubmit'],
    ];
    $form['actions']['back'] = $this->backLink($testimonial);
    return $form;
  }

  /**
   * Step 2 — confirm the transcript and the redaction.
   */
  private function buildReviewStep(array $form, FormStateInterface $form_state, EntityInterface $testimonial, array $result): array {
    if ($result['warnings']) {
      $form['warnings'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Check these'),
        '#items' => $result['warnings'],
      ];
    }

    $form['transcript'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Transcript'),
      '#description' => $this->t('Read this against the photo before saving. These publish as the customer’s own words, so a misread word is a small lie — fix anything marked [?].'),
      '#default_value' => $result['transcript'],
      '#rows' => 8,
    ];
    $form['signed_by'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Signed by'),
      '#default_value' => $result['signed_by'],
      '#description' => $this->t('Goes into "Testimonial by", which is shown publicly under the quote.'),
    ];

    if ($result['personal_info']) {
      $items = [];
      foreach ($result['personal_info'] as $r) {
        $items[] = $r['where'] !== ''
          ? $this->t('@type — @where', ['@type' => $r['type'], '@where' => $r['where']])
          : $this->t('@type', ['@type' => $r['type']]);
      }
      $form['found'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Personal details it found — check each one is covered'),
        '#items' => $items,
      ];
    }

    $form['redact'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Blur before publishing'),
    ];
    $form['redact']['help'] = [
      '#markup' => '<p>' . $this->t('Everything below the line is blurred. On a note the signature block is at the bottom, so one line usually covers it — move the line until nothing personal is left, then look at the result.') . '</p>',
    ];
    $form['redact']['blur_from'] = [
      '#type' => 'number',
      '#title' => $this->t('Blur everything below this point'),
      '#field_suffix' => $this->t('% down from the top'),
      '#min' => 10,
      '#max' => 98,
      '#step' => 1,
      '#default_value' => (int) round(($form_state->get('blur_from') ?? 0.70) * 100),
    ];
    $form['redact']['repreview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update preview'),
      '#submit' => ['::previewSubmit'],
      '#limit_validation_errors' => [['blur_from']],
    ];

    if ($preview = $form_state->get('preview_url')) {
      $form['redact']['preview'] = [
        '#type' => 'container',
        'img' => [
          '#theme' => 'image',
          '#uri' => $preview,
          '#alt' => $this->t('Note with the proposed blur'),
          '#attributes' => ['style' => 'max-width:100%;height:auto;border:1px solid #ddd;margin-top:1rem;'],
        ],
      ];
      $form['redact']['use_image'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use this blurred image as the Public image (it will be shown on the reviews page)'),
        '#default_value' => FALSE,
        '#description' => $this->t('Only tick this once you have checked the image above and nothing personal is readable.'),
      ];
    }
    elseif ($msg = $form_state->get('preview_error')) {
      $form['redact']['no_preview'] = [
        '#markup' => '<p><strong>' . $this->t('No preview: @m', ['@m' => $msg]) . '</strong><br>'
          . $this->t('The transcript is still usable. To publish a picture of this note, crop it yourself and upload it to "Public image".') . '</p>',
      ];
    }

    $form['save_text'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Save the transcript into Testimony and Testimonial by'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save to the testimonial'),
      '#button_type' => 'primary',
    ];
    $form['actions']['back'] = $this->backLink($testimonial);
    return $form;
  }

  private function backLink(EntityInterface $testimonial): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Back to the testimonial'),
      '#url' => $testimonial->toUrl('edit-form'),
      '#attributes' => ['class' => ['button']],
    ];
  }

  /**
   * Call the model, build the preview, rebuild into step 2.
   */
  public function readSubmit(array &$form, FormStateInterface $form_state): void {
    $testimonial = $form_state->get('testimonial');
    $delta = (int) ($form_state->getValue('delta') ?? 0);
    $file = $testimonial->get('field_testimonial_scan')[$delta]->entity ?? NULL;
    if (!$file) {
      $this->messenger()->addError($this->t('That scan could not be loaded.'));
      return;
    }

    $binary = @file_get_contents($file->getFileUri());
    if ($binary === FALSE) {
      $this->messenger()->addError($this->t('That scan could not be read from storage.'));
      return;
    }
    $mime = $file->getMimeType();

    try {
      $result = $this->vision->read($binary, $mime);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not read the note: @m', ['@m' => $e->getMessage()]));
      return;
    }
    $form_state->set('result', $result);

    // The blur is a separate concern from the reading: if it fails, the office
    // still gets the transcript, which is the bigger win.
    $form_state->set('delta', $delta);
    $form_state->set('blur_from', 0.70);
    $this->buildPreview($form_state);

    $form_state->setRebuild(TRUE);
  }

  /**
   * Redraw the preview at the line the office just set.
   */
  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    $pct = (int) $form_state->getValue('blur_from');
    $form_state->set('blur_from', max(0.10, min(0.98, $pct / 100)));
    $this->buildPreview($form_state);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Render the blurred preview, and the clean copy that would be saved.
   *
   * Two renders: the preview carries an outline so it is obvious where the line
   * fell, the clean one does not, because an orange rectangle has no business
   * on the public page.
   */
  private function buildPreview(FormStateInterface $form_state): void {
    $form_state->set('preview_url', NULL);
    $form_state->set('preview_error', NULL);

    $testimonial = $form_state->get('testimonial');
    $delta = (int) $form_state->get('delta');
    $file = $testimonial->get('field_testimonial_scan')[$delta]->entity ?? NULL;
    if (!$file) {
      $form_state->set('preview_error', $this->t('the scan could not be reloaded.'));
      return;
    }
    if (!$this->redactor->supports($file->getMimeType())) {
      $form_state->set('preview_error', $this->t('@m images cannot be blurred here.', ['@m' => $file->getMimeType()]));
      return;
    }
    $binary = @file_get_contents($file->getFileUri());
    if ($binary === FALSE) {
      $form_state->set('preview_error', $this->t('the scan could not be read from storage.'));
      return;
    }

    try {
      $from = (float) ($form_state->get('blur_from') ?? 0.70);
      $preview = $this->redactor->redactBand($binary, $from, TRUE);
      $clean = $this->redactor->redactBand($binary, $from, FALSE);
      $dir = 'temporary://bos-testimonial';
      $this->files->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
      $stamp = $testimonial->id() . '-' . $delta . '-' . (int) round($from * 100);
      $form_state->set('preview_url', $this->files->saveData($preview, $dir . '/preview-' . $stamp . '.png', FileSystemInterface::EXISTS_REPLACE));
      $form_state->set('clean_uri', $this->files->saveData($clean, $dir . '/clean-' . $stamp . '.png', FileSystemInterface::EXISTS_REPLACE));
    }
    catch (\Throwable $e) {
      $form_state->set('preview_error', $e->getMessage());
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $testimonial = $form_state->get('testimonial');
    $saved = [];

    if ($form_state->getValue('save_text')) {
      $transcript = trim((string) $form_state->getValue('transcript'));
      if ($transcript !== '') {
        $testimonial->set('field_testimony', [
          'value' => $transcript,
          'format' => 'basic_html',
        ]);
        $saved[] = (string) $this->t('transcript');
      }
      $by = trim((string) $form_state->getValue('signed_by'));
      if ($by !== '') {
        $testimonial->set('field_testimonial_by', $by);
      }
    }

    if ($form_state->getValue('use_image') && ($cleanUri = $form_state->get('clean_uri'))) {
      try {
        $data = (string) file_get_contents($cleanUri);
        $dir = 'public://testimonials/public/' . date('Y');
        $this->files->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
        $uri = $this->files->saveData(
          $data,
          $dir . '/note-' . $testimonial->id() . '-' . substr(hash('sha256', $data), 0, 8) . '.png',
          FileSystemInterface::EXISTS_REPLACE
        );
        $file = $this->etm->getStorage('file')->create(['uri' => $uri, 'status' => 1]);
        $file->save();
        $testimonial->set('field_testimonial_image', ['target_id' => $file->id()]);
        $saved[] = (string) $this->t('redacted image');
      }
      catch (\Throwable $e) {
        $this->messenger()->addError($this->t('The redacted image could not be saved: @m', ['@m' => $e->getMessage()]));
      }
    }

    if ($saved) {
      $testimonial->save();
      $this->messenger()->addStatus($this->t('Saved the @what. Check it over, then set Status to Approved when you are happy with it.', [
        '@what' => implode(' and ', $saved),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('Nothing was selected, so nothing was saved.'));
    }

    $form_state->setRedirectUrl(Url::fromRoute('entity.testimonial.edit_form', ['testimonial' => $testimonial->id()]));
  }

}
