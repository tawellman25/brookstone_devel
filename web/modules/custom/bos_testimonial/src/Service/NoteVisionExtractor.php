<?php

declare(strict_types=1);

namespace Drupal\bos_testimonial\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads a photographed handwritten thank-you note.
 *
 * Two jobs, and they are not equally reliable — which is the whole reason the
 * office confirms every result:
 *
 *  - TRANSCRIPTION is what these models are good at. Still a draft: handwriting
 *    is handwriting, and a misread word published as a customer's own words is
 *    a small lie.
 *  - REDACTION REGIONS are a hint, nothing more. Vision models are weak at
 *    precise coordinates, so the boxes drift. The redactor pads them heavily
 *    and the office looks at the result before anything is published; a box
 *    that is 15% off leaves half a signature showing, and an "auto-redacted"
 *    label would make that worse by inviting trust.
 *
 * Mirrors wo_material_list_management's InvoiceVisionExtractor — same provider
 * plumbing, same tolerant JSON decode, same "never act blind" posture.
 */
final class NoteVisionExtractor {

  public function __construct(
    private readonly AiProviderPluginManager $aiProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Is a vision provider configured AND does its API key resolve?
   *
   * Used to hide the button entirely rather than offer one that fails.
   */
  public function isAvailable(): bool {
    try {
      $def = $this->aiProvider->getDefaultProviderForOperationType('chat_with_image_vision');
      if (empty($def['provider_id']) || empty($def['model_id'])) {
        return FALSE;
      }
      $keyName = $this->configFactory->get('ai_provider_' . $def['provider_id'] . '.settings')->get('api_key');
      if (!$keyName) {
        return FALSE;
      }
      $key = $this->entityTypeManager->getStorage('key')->load($keyName);
      return $key && strlen((string) $key->getKeyValue()) > 10;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Read a note.
   *
   * @return array
   *   [transcript, signed_by, personal_info[], warnings[]] where each
   *   personal_info entry is [type, where, box[x,y,w,h] normalised 0..1].
   */
  public function read(string $binary, string $mimeType): array {
    $def = $this->aiProvider->getDefaultProviderForOperationType('chat_with_image_vision');
    if (empty($def['provider_id'])) {
      throw new \RuntimeException('No vision provider configured for chat_with_image_vision.');
    }
    $provider = $this->aiProvider->createInstance($def['provider_id']);

    $image = new ImageFile($binary, $mimeType, 'note');
    $message = new ChatMessage('user', $this->prompt(), [$image]);
    $input = new ChatInput([$message]);

    $output = $provider->chat($input, $def['model_id'], ['bos_testimonial_read_note']);
    $text = trim($output->getNormalized()->getText());

    return $this->normalize($this->decodeJson($text));
  }

  private function prompt(): string {
    return <<<TXT
You are reading a photograph of a handwritten thank-you note or card sent to a landscaping and irrigation company by a customer. The photo may be angled, rotated, or include the table around the card — read only the note.

Return ONLY a JSON object (no prose, no markdown fences) shaped exactly like:
{"transcript":"<the words of the note>","signed_by":"<the name it is signed with, or null>","confidence":"<high|medium|low>","personal_info":[{"type":"<signature|full_name|address|phone|email|other>","where":"<short human description of where it is>","box":[<x>,<y>,<w>,<h>]}],"unreadable":["<any word or phrase you could not read>"]}

Rules for the transcript:
- Transcribe the words of the message only. Do NOT include the signature line, the address, or a phone number in the transcript.
- Keep the customer's own wording, spelling and punctuation. Do not tidy it up, do not improve it, do not add a greeting or sign-off that is not there.
- If a word is genuinely unreadable, write [?] in its place AND list your best guess in "unreadable". Never invent a word to fill a gap.
- If the image is not a handwritten note at all, return an empty transcript and say so in "unreadable".

Rules for personal_info — this drives a redaction step, so err heavily toward flagging:
- Flag EVERY region containing a signature, a full name, a street address, a phone number or an email address. A first name alone inside the message text does NOT need flagging.
- box is [x, y, width, height] as fractions of the image, 0 to 1, origin at the TOP-LEFT. Be generous: it is far better for a box to be too big than to clip the edge of a signature.
- If you are unsure whether something is personal, flag it.
- If there is nothing personal visible, return an empty personal_info array.
TXT;
  }

  /**
   * Tolerant JSON decode — strips fences and stray prose.
   */
  private function decodeJson(string $text): array {
    $text = preg_replace('/^```(?:json)?|```$/m', '', $text);
    $text = trim($text);
    if (($start = strpos($text, '{')) !== FALSE && ($end = strrpos($text, '}')) !== FALSE) {
      $text = substr($text, $start, $end - $start + 1);
    }
    $data = json_decode($text, TRUE);
    if (!is_array($data)) {
      $this->logger->warning('Note read returned non-JSON: @t', ['@t' => substr($text, 0, 500)]);
      throw new \RuntimeException('The model did not return readable output. Try a clearer, straighter photo.');
    }
    return $data;
  }

  /**
   * Shape the output and drop anything malformed rather than trusting it.
   */
  private function normalize(array $data): array {
    $warnings = [];

    $transcript = trim((string) ($data['transcript'] ?? ''));
    if ($transcript === '') {
      $warnings[] = 'No message text was read from this image — check it is the right photo, and right way up.';
    }
    if (str_contains($transcript, '[?]')) {
      $warnings[] = 'Some words could not be read and are marked [?] — fill those in before saving.';
    }
    if (($data['confidence'] ?? '') === 'low') {
      $warnings[] = 'The model rated its own reading as low confidence. Read it against the photo carefully.';
    }
    foreach ((array) ($data['unreadable'] ?? []) as $u) {
      if (is_string($u) && trim($u) !== '') {
        $warnings[] = 'Unreadable / best guess: ' . trim($u);
      }
    }

    $regions = [];
    foreach ((array) ($data['personal_info'] ?? []) as $item) {
      $box = $item['box'] ?? NULL;
      if (!is_array($box) || count($box) !== 4) {
        // A flagged item with no usable box still matters — the office needs to
        // know it is there even though we cannot place a blur over it.
        $warnings[] = sprintf(
          'Flagged "%s" (%s) but gave no usable position — check for it yourself.',
          (string) ($item['type'] ?? 'personal info'),
          (string) ($item['where'] ?? 'location not given')
        );
        continue;
      }
      [$x, $y, $w, $h] = array_map('floatval', $box);
      if ($w <= 0 || $h <= 0) {
        continue;
      }
      $regions[] = [
        'type' => (string) ($item['type'] ?? 'other'),
        'where' => (string) ($item['where'] ?? ''),
        'box' => [
          max(0.0, min(1.0, $x)),
          max(0.0, min(1.0, $y)),
          min(1.0, $w),
          min(1.0, $h),
        ],
      ];
    }

    if ($regions === [] && $warnings === []) {
      $warnings[] = 'Nothing personal was detected. That is worth a second look yourself before publishing — a missed signature is the whole risk here.';
    }

    return [
      'transcript' => $transcript,
      'signed_by' => isset($data['signed_by']) && is_string($data['signed_by']) ? trim($data['signed_by']) : '',
      'personal_info' => $regions,
      'warnings' => $warnings,
    ];
  }

}
