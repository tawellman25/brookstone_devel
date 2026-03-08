<?php

declare(strict_types=1);

namespace Drupal\estimate_intake\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Looks up BOS records from estimate request intake info.
 */
final class EstimateRequestIntakeLookup {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Finds properties matching a typed address string.
   * Parses address into components, scores each candidate match.
   *
   * @param string $address
   *   Raw address typed by staff (may include city, state, zip).
   *
   * @return array
   *   Scored matches: [['entity' => $property, 'score' => int], ...]
   *   Sorted by score DESC. Empty array if no matches.
   */
  public function findProperties(string $address): array {
    if (empty(trim($address))) {
      return [];
    }

    // Parse address components.
    // Expected formats: "123 Main St" or "123 Main St, Delta, CO 81416"
    $parts = array_map('trim', explode(',', $address));
    $streetOnly = $parts[0] ?? '';
    $cityRaw = $parts[1] ?? '';
    $stateZipRaw = $parts[2] ?? '';

    // Extract zip code (5 digits).
    $zip = '';
    if (preg_match('/\b(\d{5})\b/', $stateZipRaw, $zipMatch)) {
      $zip = $zipMatch[1];
    }
    // Also check if zip is in part[1] (e.g. "Delta CO 81416").
    if (empty($zip) && preg_match('/\b(\d{5})\b/', $cityRaw, $zipMatch)) {
      $zip = $zipMatch[1];
    }

    // Normalize city — strip state abbreviation and zip.
    $city = trim(preg_replace('/\b[A-Z]{2}\b|\b\d{5}\b/', '', strtolower($cityRaw)));

    if (empty($streetOnly)) {
      return [];
    }

    // Query properties by street only (LIKE on street number + name).
    $storage = $this->entityTypeManager->getStorage('properties');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'property')
      ->condition('field_street_address', '%' . $streetOnly . '%', 'LIKE')
      ->range(0, 20)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $properties = $storage->loadMultiple($ids);

    $scored = [];
    foreach ($properties as $property) {
      $score = 0;

      // Street match (always true since we queried on it, but score it).
      $storedStreet = strtolower($property->get('field_street_address')->value ?? '');
      $searchStreet = strtolower($streetOnly);
      if (str_contains($storedStreet, $searchStreet) || str_contains($searchStreet, $storedStreet)) {
        $score += 1;
      }

      // Zip match via field_zipcode_reference → zipcodes.field_zipcode.
      if (!empty($zip) && !$property->get('field_zipcode_reference')->isEmpty()) {
        $zipcodeRef = $property->get('field_zipcode_reference')->referencedEntities();
        if (!empty($zipcodeRef)) {
          $zipEntity = reset($zipcodeRef);
          $storedZip = $zipEntity->get('field_zipcode')->value ?? '';
          if ($storedZip === $zip) {
            $score += 2;
          }
        }
      }

      // City match via field_zipcode_reference → zipcodes.field_city → city.field_city_name.
      if (!empty($city) && !$property->get('field_zipcode_reference')->isEmpty()) {
        $zipcodeRef = $property->get('field_zipcode_reference')->referencedEntities();
        if (!empty($zipcodeRef)) {
          $zipEntity = reset($zipcodeRef);
          $storedCity = '';
          if ($zipEntity->hasField('field_city') && !$zipEntity->get('field_city')->isEmpty()) {
            $cityEntities = $zipEntity->get('field_city')->referencedEntities();
            if (!empty($cityEntities)) {
              $cityEntity = reset($cityEntities);
              if ($cityEntity->hasField('field_city_name')) {
                $storedCity = strtolower($cityEntity->get('field_city_name')->value ?? '');
              }
            }
          }
          if (!empty($storedCity) && str_contains($storedCity, $city)) {
            $score += 1;
          }
        }
      }

      $scored[] = [
        'entity' => $property,
        'score' => $score,
      ];
    }

    // Sort by score descending.
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    return $scored;
  }

  /**
   * Find the most recent owner for a property via ownership_record.
   *
   * @return int|null
   *   The owner user ID, or NULL if no record found.
   */
  public function findLatestOwner(int $property_id): ?int {
    if ($property_id <= 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('ownership_record');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'record')
      ->condition('field_property_reference.target_id', $property_id)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $record = $storage->load(array_values($ids)[0]);
    if ($record === NULL || !$record->hasField('field_property_owner')) {
      return NULL;
    }

    $uid = (int) ($record->get('field_property_owner')->target_id ?? 0);
    return $uid > 0 ? $uid : NULL;
  }

  /**
   * Find an existing contact or create a new one.
   *
   * Matches on email first (direct field), then phone (via phone_number
   * sub-entity). If no match, creates a new contact (and phone_number
   * sub-entity if phone provided).
   *
   * @return array{id: int, created: bool}
   */
  public function findOrCreateContact(string $first_name, string $last_name, string $phone, string $email): array {
    $phone = $this->normalizePhone($phone);
    $contact_storage = $this->entityTypeManager->getStorage('contacts');

    // 1. Try email match (direct field on contacts.contact).
    if ($email !== '') {
      $ids = $contact_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'contact')
        ->condition('field_email', $email)
        ->range(0, 1)
        ->execute();

      if (!empty($ids)) {
        return ['id' => (int) array_values($ids)[0], 'created' => FALSE];
      }
    }

    // 2. Try phone match (two-step: find phone_number entities, then contacts).
    if ($phone !== '') {
      $phone_storage = $this->entityTypeManager->getStorage('phone_number');
      $phone_ids = $phone_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'contacts')
        ->condition('field_phone_number', $phone)
        ->range(0, 10)
        ->execute();

      if (!empty($phone_ids)) {
        // Find contacts referencing any of these phone_number entities.
        $ids = $contact_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'contact')
          ->condition('field_phone_number', array_values($phone_ids), 'IN')
          ->range(0, 1)
          ->execute();

        if (!empty($ids)) {
          return ['id' => (int) array_values($ids)[0], 'created' => FALSE];
        }
      }
    }

    // 3. No match — create new contact.
    return $this->createContact($first_name, $last_name, $phone, $email);
  }

  /**
   * Run the full intake lookup.
   *
   * @return array{properties: EntityInterface[], owner_uid: int|null, contact_id: int, contact_created: bool}
   */
  public function orchestrate(string $address, string $first_name, string $last_name, string $phone, string $email): array {
    $scored = $this->findProperties($address);

    // Resolve scored matches to a flat properties array for the caller.
    $properties = [];
    if (!empty($scored)) {
      $topScore = $scored[0]['score'];
      $topMatches = array_values(array_filter($scored, fn($m) => $m['score'] === $topScore));
      $properties = array_map(fn($m) => $m['entity'], $topMatches);
    }

    $owner_uid = NULL;
    if (count($properties) === 1) {
      $prop = reset($properties);
      $owner_uid = $this->findLatestOwner((int) $prop->id());
    }

    $contact_result = ['id' => 0, 'created' => FALSE];
    if ($first_name !== '' || $last_name !== '' || $phone !== '' || $email !== '') {
      $contact_result = $this->findOrCreateContact($first_name, $last_name, $phone, $email);
    }

    return [
      'properties' => $properties,
      'owner_uid' => $owner_uid,
      'contact_id' => $contact_result['id'],
      'contact_created' => $contact_result['created'],
    ];
  }

  /**
   * Create a new contacts.contact entity, with optional phone_number sub-entity.
   *
   * @return array{id: int, created: bool}
   */
  private function createContact(string $first_name, string $last_name, string $phone, string $email): array {
    $values = [
      'type' => 'contact',
      'title' => trim($first_name . ' ' . $last_name),
    ];

    if ($first_name !== '') {
      $values['field_first_name'] = $first_name;
    }
    if ($last_name !== '') {
      $values['field_last_name'] = $last_name;
    }
    if ($email !== '') {
      $values['field_email'] = $email;
    }

    try {
      $contact = $this->entityTypeManager->getStorage('contacts')->create($values);
      $contact->save();
      $contact_id = (int) $contact->id();

      // Create phone_number sub-entity and link to contact.
      if ($phone !== '' && $contact_id > 0) {
        $phone_entity = $this->entityTypeManager->getStorage('phone_number')->create([
          'type' => 'contacts',
          'field_phone_number' => $phone,
        ]);
        $phone_entity->save();

        $contact->set('field_phone_number', ['target_id' => (int) $phone_entity->id()]);
        $contact->save();
      }

      $this->loggerFactory->get('estimate_intake')
        ->info('Created contact @cid (@name) from estimate intake.', [
          '@cid' => $contact_id,
          '@name' => trim($first_name . ' ' . $last_name),
        ]);

      return ['id' => $contact_id, 'created' => TRUE];
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('estimate_intake')
        ->error('Failed creating contact from estimate intake: @msg', [
          '@msg' => $e->getMessage(),
        ]);
      return ['id' => 0, 'created' => FALSE];
    }
  }

  /**
   * Strip non-digit characters from a phone string.
   */
  private function normalizePhone(string $phone): string {
    return preg_replace('/\D/', '', $phone);
  }

}
