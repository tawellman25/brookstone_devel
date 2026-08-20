<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Service;

use Drupal\bos_wo_intake\Service\PropertyMatchNormalizer;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolve a public submission to a BOS property — ENTIRELY server-side, after
 * the submitter has already committed the form (§6.0 invariant). The submitter
 * never sees, selects, or learns the outcome. Reuses bos_wo_intake's shared
 * normalizers (no second normalizer).
 *
 * Input is last name + street + ZIP + phone + email — never a nickname.
 */
final class ServiceRequestPropertyMatcher {

  private const STREET_LIKE_CAP = 60;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PropertyMatchNormalizer $normalizer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array{
   *   status: string,               // matched | ambiguous | unmatched
   *   property_id: int|null,         // set only on a single confident match
   *   candidates: array<int,array>,  // office-facing evidence (id/nickname/street/corroborated)
   *   flags: string[]                // ambiguous_property | unmatched_property | zip_out_of_area | no_sprinkler_system
   * }
   */
  public function match(string $lastName, string $street, string $zip, string $phone = '', string $email = ''): array {
    $flags = [];
    $normStreet = $this->normalizer->normalizeStreet($street);
    $normLast = $this->normalizer->normalizeText($lastName);

    // ZIP → zipcodes ids. Unknown ZIP: still accept, flag, skip the ZIP filter.
    $zipIds = $this->zipcodeIds($zip);
    if ($zip !== '' && !$zipIds) {
      $flags[] = 'zip_out_of_area';
    }

    // Cheap SQL prefilter: LIKE on the raw street core (first comma part).
    $streetCore = trim(explode(',', $street)[0]);
    $propStorage = $this->entityTypeManager->getStorage('properties');
    $query = $propStorage->getQuery()->accessCheck(FALSE)->condition('type', 'property');
    if ($streetCore !== '') {
      $query->condition('field_street_address', '%' . $streetCore . '%', 'LIKE');
    }
    $ids = $query->sort('id', 'ASC')->range(0, self::STREET_LIKE_CAP)->execute();

    // Server-side normalized street + ZIP match.
    $matches = [];
    foreach ($propStorage->loadMultiple($ids) as $property) {
      if (!$this->zipMatches($property, $zipIds)) {
        continue;
      }
      $candStreet = $this->normalizer->normalizeStreet((string) ($property->get('field_street_address')->value ?? ''));
      if ($normStreet === '' || !str_contains($candStreet, $normStreet)) {
        continue;
      }
      $matches[(int) $property->id()] = $property;
    }

    $candidates = [];
    foreach ($matches as $pid => $property) {
      $candidates[] = [
        'id' => $pid,
        'nickname' => (string) ($property->get('field_nickname')->value ?? ''),
        'street' => (string) ($property->get('field_street_address')->value ?? ''),
        'corroborated' => $this->nameCorroborates($property, $normLast),
      ];
    }

    if (count($matches) === 1) {
      $pid = (int) array_key_first($matches);
      if (!$this->hasSprinklerZones($pid)) {
        $flags[] = 'no_sprinkler_system';
      }
      return ['status' => 'matched', 'property_id' => $pid, 'candidates' => $candidates, 'flags' => $flags];
    }
    if (count($matches) > 1) {
      $flags[] = 'ambiguous_property';
      return ['status' => 'ambiguous', 'property_id' => NULL, 'candidates' => $candidates, 'flags' => $flags];
    }
    $flags[] = 'unmatched_property';
    return ['status' => 'unmatched', 'property_id' => NULL, 'candidates' => [], 'flags' => $flags];
  }

  /**
   * Does the submitted email OR phone match this property's primary contact?
   *
   * Gate 3 uses this to gate the "already on our list" disclosure — a street
   * address alone never unlocks it (enumeration control). Digits-only phone
   * compare; case-insensitive email compare.
   */
  public function contactCorroborates(int $propertyId, string $phone, string $email): bool {
    $property = $this->entityTypeManager->getStorage('properties')->load($propertyId);
    if (!$property || !$property->hasField('field_primary_contact_ref') || $property->get('field_primary_contact_ref')->isEmpty()) {
      return FALSE;
    }
    $contact = $property->get('field_primary_contact_ref')->entity;
    if (!$contact) {
      return FALSE;
    }
    $email = strtolower(trim($email));
    if ($email !== '' && $contact->hasField('field_email') && !$contact->get('field_email')->isEmpty()) {
      if (strtolower(trim((string) $contact->get('field_email')->value)) === $email) {
        return TRUE;
      }
    }
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    if ($phoneDigits !== '' && strlen($phoneDigits) >= 7 && $contact->hasField('field_phone_number')) {
      foreach ($contact->get('field_phone_number') as $item) {
        $phoneEntity = $item->entity;
        if ($phoneEntity && $phoneEntity->hasField('field_phone_number') && !$phoneEntity->get('field_phone_number')->isEmpty()) {
          if (preg_replace('/\D+/', '', (string) $phoneEntity->get('field_phone_number')->value) === $phoneDigits) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * zipcodes entity ids for a submitted ZIP (matched on field_zipcode).
   *
   * @return int[]
   */
  private function zipcodeIds(string $zip): array {
    $zip = trim($zip);
    if ($zip === '') {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('zipcodes')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_zipcode', $zip)
      ->sort('id', 'ASC')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  private function zipMatches(EntityInterface $property, array $zipIds): bool {
    if (!$zipIds) {
      // Unknown/out-of-area ZIP — no filter (already flagged).
      return TRUE;
    }
    if (!$property->hasField('field_zipcode_reference') || $property->get('field_zipcode_reference')->isEmpty()) {
      return FALSE;
    }
    return in_array((int) $property->get('field_zipcode_reference')->target_id, $zipIds, TRUE);
  }

  private function nameCorroborates(EntityInterface $property, string $normLast): bool {
    if ($normLast === '') {
      return FALSE;
    }
    // Nickname token-order-insensitive (same rule as the WO resolver).
    $nick = $this->normalizer->normalizeText((string) ($property->get('field_nickname')->value ?? ''));
    foreach (explode(' ', $normLast) as $token) {
      if ($token !== '' && $this->normalizer->tokenMatches($nick, $token)) {
        return TRUE;
      }
    }
    // Primary contact surname.
    if ($property->hasField('field_primary_contact_ref') && !$property->get('field_primary_contact_ref')->isEmpty()) {
      $contact = $property->get('field_primary_contact_ref')->entity;
      if ($contact && $contact->hasField('field_last_name') && !$contact->get('field_last_name')->isEmpty()) {
        $surname = $this->normalizer->normalizeText((string) $contact->get('field_last_name')->value);
        if ($surname !== '' && str_contains($normLast, $surname)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  private function hasSprinklerZones(int $propertyId): bool {
    $ids = $this->entityTypeManager->getStorage('property_sprinkler_system')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_property', $propertyId)
      ->exists('field_total_zones')
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    return (bool) $ids;
  }

}
