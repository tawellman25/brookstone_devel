<?php

declare(strict_types=1);

namespace Drupal\wo_material_list_management\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Extract material line items from a supplier-invoice IMAGE or PDF page using a
 * vision model (default: openai/gpt-4o via the `ai` module's
 * chat_with_image_vision operation).
 *
 * Returns rows shaped like MaterialListImportService expects
 * (identifier, description, quantity, unit_cost) plus a `uom` hint so the
 * preview can flag case-priced lines. The office ALWAYS reviews the preview —
 * photos are messy (glare, rotation, multi-page), so this never imports blind.
 */
final class InvoiceVisionExtractor {

  public function __construct(
    private readonly AiProviderPluginManager $aiProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Is a vision provider configured AND does its API key resolve to a value?
   * Used to show/hide the photo-upload option so users never see a dead button.
   */
  public function isAvailable(): bool {
    try {
      $def = $this->aiProvider->getDefaultProviderForOperationType('chat_with_image_vision');
      if (empty($def['provider_id']) || empty($def['model_id'])) {
        return FALSE;
      }
      // The provider settings name its key entity (e.g. anthropic_api_key).
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
   * Extract line items from one invoice image/PDF page.
   *
   * @param string $binary
   *   Raw file bytes (jpg/png/webp/pdf-page-image).
   * @param string $mimeType
   *   e.g. image/jpeg, image/png.
   *
   * @return array
   *   [vendor, document_type, warnings[], rows[]] where each row is
   *   [identifier, description, quantity, unit_cost, uom].
   */
  public function extract(string $binary, string $mimeType): array {
    $def = $this->aiProvider->getDefaultProviderForOperationType('chat_with_image_vision');
    if (empty($def['provider_id'])) {
      throw new \RuntimeException('No vision provider configured for chat_with_image_vision.');
    }
    $provider = $this->aiProvider->createInstance($def['provider_id']);

    $image = new ImageFile($binary, $mimeType, 'invoice');
    $message = new ChatMessage('user', $this->prompt(), [$image]);
    $input = new ChatInput([$message]);

    $output = $provider->chat($input, $def['model_id'], ['wo_material_invoice_extract']);
    $text = trim($output->getNormalized()->getText());

    $data = $this->decodeJson($text);
    return $this->normalize($data);
  }

  /**
   * The extraction instruction. Steers the model to per-unit net price and a
   * UOM hint, and to ignore photo clutter / rotation.
   */
  private function prompt(): string {
    return <<<TXT
You are extracting line items from a supplier invoice, order ticket, or return/credit for a landscaping & irrigation company. The photo may be rotated, angled, or have clutter around it — read only the invoice document and ignore everything else.

Return ONLY a JSON object (no prose, no markdown fences) shaped exactly like:
{"vendor":"<supplier name if visible>","document_type":"<invoice|order|return|credit|unknown>","line_items":[{"identifier":"<vendor item/part/product number>","description":"<item description>","quantity":<number>,"unit_price":<number>,"uom":"<EA|CASE|BOX|FT|ROLL|... or empty>"}]}

Rules:
- identifier: the vendor's item/part/product number. Labels include "Pn:", "Part No", "Product ID", "Item #", "SKU". If a line shows both a catalog code and a "Pn:" number, prefer the catalog code (e.g. "PV005-180") and put the other in the description if useful.
- unit_price: the PER-UNIT net price the customer pays (labels: "Unit Price", "Your Price", "Net"). Strip "/ea", "$", commas. NEVER use retail/list price or the extended/line total.
- quantity: the numeric quantity shipped (or ordered if no ship qty). KEEP a negative sign for returns/credits.
- uom: the unit of measure if shown (EA, CASE, BOX, FT, ROLL...). A CASE price is not a per-each price — always capture this when present.
- Skip subtotal, tax, freight, shipping, and total rows, and any non-line-item text.
- If a value is unreadable, use null. Return strictly valid JSON.
TXT;
  }

  /**
   * Tolerant JSON decode — strips ```json fences and leading/trailing prose.
   */
  private function decodeJson(string $text): array {
    $text = preg_replace('/^```(?:json)?|```$/m', '', $text);
    $text = trim($text);
    // Grab the outermost {...} if the model added stray text.
    if (($start = strpos($text, '{')) !== FALSE && ($end = strrpos($text, '}')) !== FALSE) {
      $text = substr($text, $start, $end - $start + 1);
    }
    $data = json_decode($text, TRUE);
    if (!is_array($data)) {
      $this->logger->warning('Invoice extraction returned non-JSON: @t', ['@t' => substr($text, 0, 500)]);
      throw new \RuntimeException('The model did not return readable invoice data. Try a clearer photo.');
    }
    return $data;
  }

  /**
   * Shape the model output into import rows + surface warnings.
   */
  private function normalize(array $data): array {
    $warnings = [];
    $docType = strtolower((string) ($data['document_type'] ?? 'unknown'));
    if (in_array($docType, ['return', 'credit'], TRUE)) {
      $warnings[] = 'This looks like a RETURN/CREDIT ticket (negative quantities) — review before importing onto a job.';
    }
    $rows = [];
    foreach ($data['line_items'] ?? [] as $li) {
      $id = trim((string) ($li['identifier'] ?? ''));
      $desc = trim((string) ($li['description'] ?? ''));
      if ($id === '' && $desc === '') {
        continue;
      }
      $uom = strtoupper(trim((string) ($li['uom'] ?? '')));
      if ($uom !== '' && !in_array($uom, ['EA', 'EACH', ''], TRUE)) {
        $warnings[] = sprintf('"%s" is priced per %s — confirm the per-each cost.', $desc ?: $id, $uom);
      }
      $rows[] = [
        'identifier' => $id,
        'description' => $desc,
        'quantity' => (string) ($li['quantity'] ?? ''),
        'unit_cost' => is_numeric($li['unit_price'] ?? NULL) ? (string) $li['unit_price'] : '',
        'uom' => $uom,
      ];
    }
    return [
      'vendor' => trim((string) ($data['vendor'] ?? '')),
      'document_type' => $docType,
      'warnings' => $warnings,
      'rows' => $rows,
    ];
  }

}
