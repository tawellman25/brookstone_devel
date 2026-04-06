<?php

declare(strict_types=1);

namespace Drupal\estimate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects to the property add form with pre-fill query params.
 *
 * Parses the estimate request's requestor address and passes the parsed
 * values + request ID as query parameters. A form_alter in estimate.module
 * picks them up to pre-fill the property form.
 */
final class EstimateRequestCreatePropertyController extends ControllerBase {

  /**
   * Redirect to the property add form with parsed address data.
   */
  public function redirectToPropertyForm($estimate_request): RedirectResponse {
    $req = $estimate_request;

    $query = ['from_estimate_request' => (int) $req->id()];

    // Parse requestor address.
    $raw = '';
    if ($req->hasField('field_requestor_address') && !$req->get('field_requestor_address')->isEmpty()) {
      $raw = trim((string) $req->get('field_requestor_address')->value);
    }

    if ($raw !== '') {
      $parsed = $this->parseAddress($raw);
      $query['street'] = $parsed['street'];
      if ($parsed['zip'] !== '') {
        $query['zip'] = $parsed['zip'];
      }
    }

    // Pass the full raw address for the geocode search box.
    if ($raw !== '') {
      $query['geocode_address'] = $raw;
    }

    // Pass owner if available.
    if ($req->hasField('field_owner') && !$req->get('field_owner')->isEmpty()) {
      $query['owner_uid'] = (int) $req->get('field_owner')->target_id;
    }

    // Pass contact if available.
    if ($req->hasField('field_contact') && !$req->get('field_contact')->isEmpty()) {
      $query['contact_id'] = (int) $req->get('field_contact')->target_id;
    }

    $url = Url::fromRoute('eck.entity.add', [
      'eck_entity_type' => 'properties',
      'eck_entity_bundle' => 'property',
    ], ['query' => $query]);

    return new RedirectResponse($url->toString());
  }

  /**
   * Parse a free-text address into street, city, state, zip.
   */
  private function parseAddress(string $raw): array {
    $result = ['street' => '', 'city' => '', 'state' => '', 'zip' => ''];

    // Extract zip code (5-digit, optionally with -4).
    if (preg_match('/\b(\d{5})(?:-\d{4})?\s*$/', $raw, $m)) {
      $result['zip'] = $m[1];
      $raw = trim(substr($raw, 0, -strlen($m[0])));
    }

    // Extract 2-letter state abbreviation at the end.
    if (preg_match('/\b([A-Z]{2})\s*$/i', $raw, $m)) {
      $result['state'] = strtoupper($m[1]);
      $raw = trim(substr($raw, 0, -strlen($m[0])));
    }

    $raw = rtrim($raw, ', ');

    // Split remaining into street and city.
    if (str_contains($raw, ',')) {
      $parts = explode(',', $raw, 2);
      $result['street'] = trim($parts[0]);
      $result['city'] = trim($parts[1] ?? '');
    }
    else {
      $street_suffixes = ['st', 'ave', 'avenue', 'blvd', 'boulevard', 'dr', 'drive',
        'ln', 'lane', 'rd', 'road', 'ct', 'court', 'pl', 'place', 'way',
        'cir', 'circle', 'trl', 'trail', 'pkwy', 'parkway', 'hwy', 'highway'];

      $words = preg_split('/\s+/', $raw);
      $split_pos = NULL;

      for ($i = count($words) - 1; $i >= 2; $i--) {
        $prev = strtolower($words[$i - 1]);
        if (in_array($prev, $street_suffixes, TRUE) || preg_match('/^\d+$/', $prev)) {
          $split_pos = $i;
          break;
        }
      }

      if ($split_pos !== NULL) {
        $result['street'] = implode(' ', array_slice($words, 0, $split_pos));
        $result['city'] = implode(' ', array_slice($words, $split_pos));
      }
      else {
        $result['street'] = $raw;
      }
    }

    return $result;
  }

}
