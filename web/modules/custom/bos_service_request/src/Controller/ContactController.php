<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the public /contact page (copy + form) and /thank-you/contact.
 * Normal-themed content pages (site nav, breadcrumb, sitewide footer).
 * Uses ControllerBase's built-in formBuilder()/config() — no constructor needed.
 */
final class ContactController extends ControllerBase {

  public function page(): array {
    $phone = (string) ($this->config('bos_service_request.settings')->get('office_phone') ?: '970-835-9661');
    return [
      '#theme' => 'bos_contact',
      '#office_phone' => $phone,
      '#office_email' => 'office@brookstoneoutdoors.com',
      '#office_hours' => "Monday – Thursday: 9:00 a.m. – 4:00 p.m.\nFriday: 9:00 a.m. – 12:00 p.m.",
      '#office_address' => "20143 Austin Rd\nAustin, CO 81410",
      // Google Maps embed keyed on the Austin Road plus code (no API key needed).
      '#map_src' => 'https://www.google.com/maps?q=Q2R7%2BQ2%20Austin%2C%20Orchard%20City%2C%20CO&output=embed',
      '#contact_form' => $this->formBuilder()->getForm('Drupal\bos_service_request\Form\ContactForm'),
      '#attached' => ['library' => ['bos_service_request/contact']],
    ];
  }

  public function thankYou(): array {
    $topic = (string) \Drupal::request()->query->get('t', '');
    return [
      '#theme' => 'bos_contact_thankyou',
      '#show_links' => in_array($topic, ['quote', 'commercial'], TRUE),
      '#attached' => ['library' => ['bos_service_request/contact']],
      '#cache' => ['contexts' => ['url.query_args:t']],
    ];
  }

}
