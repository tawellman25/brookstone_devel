<?php

declare(strict_types=1);

namespace Drupal\contract_snow\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Snow Removal Service Agreement PDF (P2).
 *
 * Renders the 2-page agreement Twig template to a PDF via the same stack as the
 * backflow report (entity_print + dompdf). Deterministic: the same contract +
 * template version renders the same document. Draft/preview is streamed inline;
 * it is not stored (the executed/signed copy is the retained artifact — P3).
 */
class SnowAgreementController extends ControllerBase {

  /**
   * Streams the agreement PDF for a snow contract.
   */
  public function pdf(EntityInterface $contracts) {
    if ($contracts->bundle() !== 'snow_removal') {
      throw new NotFoundHttpException();
    }
    $data = $this->buildData($contracts);

    $build = [
      '#theme' => 'snow_agreement',
      '#data' => $data,
    ];
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);

    $engine = \Drupal::service('plugin.manager.entity_print.print_engine')->createInstance('dompdf');
    $engine->addPage($html);

    $filename = 'snow-agreement-' . ($data['contract_number'] ?: ('contract-' . $contracts->id())) . '.pdf';
    return new Response($engine->getBlob(), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
  }

  /**
   * Assemble the agreement data from the contract + its property/customer.
   */
  protected function buildData(EntityInterface $c): array {
    $val = fn(string $f) => ($c->hasField($f) && !$c->get($f)->isEmpty()) ? $c->get($f)->value : NULL;
    $money = fn($v) => ($v === NULL || $v === '') ? '' : '$' . number_format((float) $v, 2);

    $year = (int) (preg_match('/(\d{4})/', (string) $val('field_contract_year'), $m) ? $m[1] : date('Y'));
    $method_map = ['automatic' => 'Automatic', 'on_call' => 'On-Call'];

    // Property + customer/contact resolution (defensive — office fills gaps).
    $property = ($c->hasField('field_property') && !$c->get('field_property')->isEmpty()) ? $c->get('field_property')->entity : NULL;
    $property_name = '';
    $property_address = '';
    $customer_name = '';
    $contact_phone = '';
    $contact_email = '';
    if ($property) {
      $property_name = $property->hasField('field_nickname') && !$property->get('field_nickname')->isEmpty()
        ? (string) $property->get('field_nickname')->value : $property->label();
      if ($property->hasField('field_full_address') && !$property->get('field_full_address')->isEmpty()) {
        $property_address = trim(strip_tags((string) $property->get('field_full_address')->value));
      }
      [$customer_name, $contact_phone, $contact_email] = $this->resolveCustomer($property);
    }

    // Snow trigger options (snow_trigger vocab) for checkbox rendering.
    $trigger_tid = ($c->hasField('field_snow_trigger') && !$c->get('field_snow_trigger')->isEmpty())
      ? (int) $c->get('field_snow_trigger')->target_id : 0;
    $trigger_options = [];
    $tstore = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $tterms = $tstore->loadByProperties(['vid' => 'snow_trigger']);
    uasort($tterms, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
    foreach ($tterms as $t) {
      $trigger_options[] = ['label' => $t->label(), 'selected' => ((int) $t->id() === $trigger_tid)];
    }

    // QR encodes the stable contract canonical URL (contains the entity id).
    $qr_target = '';
    try {
      $qr_target = $c->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
    }
    catch (\Throwable $e) {
      $qr_target = 'BOS-SNOW:' . ($val('field_snow_contract_number') ?: $c->id());
    }

    return [
      'contract_number' => (string) ($val('field_snow_contract_number') ?? ''),
      'template_version' => (string) ($val('field_snow_template_version') ?? \CONTRACT_SNOW_TEMPLATE_VERSION),
      'season' => $year . '–' . ($year + 1),
      'season_start' => 'November 1, ' . $year,
      'season_end' => 'May 31, ' . ($year + 1),
      'customer_name' => $customer_name,
      'property_name' => $property_name,
      'property_address' => $property_address,
      'contact_phone' => $contact_phone,
      'contact_email' => $contact_email,
      'service_method' => $method_map[$val('field_snow_service_method')] ?? '',
      'trigger_options' => $trigger_options,
      'ice_authorized' => (bool) $val('field_snow_ice_authorized'),
      'shoveling_included' => (bool) $val('field_shoveling_labor_included'),
      'rates' => [
        ['tier' => '0–2"', 'rate' => $money($val('field_plow_rate_0_2'))],
        ['tier' => '2–4"', 'rate' => $money($val('field_plow_rate_2_4'))],
        ['tier' => '4–6"', 'rate' => $money($val('field_plow_rate_4_6'))],
        ['tier' => '6" or more', 'rate' => $money($val('field_plow_rate_6_plus'))],
      ],
      'salt_rate' => $money($val('field_salt_rate')),
      'mag_rate' => $money($val('field_mag_rate')),
      'shovel_rate' => $money($val('field_shovel_rate')),
      'instructions' => $val('field_snow_property_instructions') ? trim(strip_tags((string) $c->get('field_snow_property_instructions')->value)) : '',
      'logo_uri' => $this->logoDataUri(),
      'qr_uri' => $this->qrDataUri($qr_target),
    ];
  }

  /**
   * Best-effort customer name + phone + email from the property.
   */
  protected function resolveCustomer(EntityInterface $property): array {
    $name = $phone = $email = '';
    // Primary contact on the property, if set.
    if ($property->hasField('field_primary_contact_ref') && !$property->get('field_primary_contact_ref')->isEmpty()) {
      $contact = $property->get('field_primary_contact_ref')->entity;
      if ($contact) {
        $name = $contact->label();
        if ($contact->hasField('field_phone_number') && !$contact->get('field_phone_number')->isEmpty()) {
          $ph = $contact->get('field_phone_number')->entity;
          if ($ph && $ph->hasField('field_phone_number') && !$ph->get('field_phone_number')->isEmpty()) {
            $phone = (string) $ph->get('field_phone_number')->value;
          }
        }
      }
    }
    return [$name, $phone, $email];
  }

  /**
   * Brookstone round emblem as a base64 data: URI (dompdf-safe).
   */
  protected function logoDataUri(): ?string {
    $path = \Drupal::service('extension.list.module')->getPath('bos_service_request') . '/assets/bo-logo-round.png';
    if (!is_file($path)) {
      return NULL;
    }
    return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
  }

  /**
   * QR PNG (endroid v6) as a base64 data: URI.
   */
  protected function qrDataUri(string $data): ?string {
    if ($data === '') {
      return NULL;
    }
    $result = (new Builder(
      writer: new PngWriter(),
      data: $data,
      encoding: new Encoding('UTF-8'),
      errorCorrectionLevel: ErrorCorrectionLevel::High,
      size: 220,
      margin: 8,
    ))->build();
    return 'data:image/png;base64,' . base64_encode($result->getString());
  }

}
