<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;

/**
 * Provision a brand-new customer from a service request: property + contact +
 * phone + client user + customer_profile + ownership_record, in one transaction.
 *
 * Mirrors the BOS ownership model (property ↔ latest ownership_record ↔ client
 * user ↔ customer_profile ↔ primary contact). Nothing here schedules or bills;
 * it only creates the customer records so the office can then create the WO.
 */
final class CustomerProvisioningService {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly PasswordGeneratorInterface $passwordGenerator,
  ) {}

  /**
   * @param array $in
   *   property: nickname, street_address, full_address, zip, lat, lng
   *   client:   first_name, last_name, email, phone, client_type_id
   *
   * @return array
   *   ['property','user','profile','contact','ownership'] entities.
   */
  public function provision(array $in): array {
    $conn = \Drupal::database();
    $tx = $conn->startTransaction();
    try {
      $first = trim((string) ($in['first_name'] ?? ''));
      $last = trim((string) ($in['last_name'] ?? ''));
      $email = trim((string) ($in['email'] ?? ''));
      $phone = trim((string) ($in['phone'] ?? ''));

      // 1. Zipcode reference (resolve existing; leave empty if unknown).
      $zipId = NULL;
      $zip = preg_replace('/[^0-9]/', '', (string) ($in['zip'] ?? ''));
      if ($zip !== '') {
        $ids = $this->etm->getStorage('zipcodes')->getQuery()->accessCheck(FALSE)
          ->condition('field_zipcode', $zip)->range(0, 1)->execute();
        $zipId = $ids ? reset($ids) : NULL;
      }

      // 2. Property.
      $property = $this->etm->getStorage('properties')->create([
        'type' => 'property',
        'field_nickname' => (string) ($in['nickname'] ?? trim("$last $first")),
        'field_street_address' => (string) ($in['street_address'] ?? ''),
        'field_full_address' => (string) ($in['full_address'] ?? ''),
      ]);
      if (!empty($in['lat']) && !empty($in['lng'])) {
        $property->set('field_geofield', ['value' => 'POINT (' . (float) $in['lng'] . ' ' . (float) $in['lat'] . ')']);
      }
      if ($zipId) {
        $property->set('field_zipcode_reference', ['target_id' => $zipId]);
      }
      $property->save();

      // 3. Phone number (sub-entity of the contact).
      $phoneId = NULL;
      if ($phone !== '') {
        $pn = $this->etm->getStorage('phone_number')->create([
          'type' => 'contacts',
          'field_phone_number' => $phone,
        ]);
        $pn->save();
        $phoneId = $pn->id();
      }

      // 4. Contact.
      $contact = $this->etm->getStorage('contacts')->create([
        'type' => 'contact',
        'field_first_name' => $first,
        'field_last_name' => $last,
      ]);
      if ($email !== '') {
        $contact->set('field_email', $email);
      }
      if ($phoneId) {
        $contact->set('field_phone_number', ['target_id' => $phoneId]);
      }
      $contact->save();

      // 5. Client user (role: client). No welcome email; portal access is
      //    governed by the profile, not this account being active.
      $username = $this->uniqueUsername($email !== '' ? $email : trim("$first $last"));
      $user = $this->etm->getStorage('user')->create([
        'name' => $username,
        'mail' => $email !== '' ? $email : NULL,
        'status' => 1,
        'pass' => $this->passwordGenerator->generate(24),
        'roles' => ['client'],
      ]);
      $user->save();

      // 6. Customer profile (field_client_type required). Primary contact set
      //    explicitly so the customer module doesn't auto-create a blank one.
      $profile = $this->etm->getStorage('profile')->create([
        'type' => 'customer_profile',
        'uid' => $user->id(),
        'is_default' => TRUE,
        'status' => 1,
        'field_client_type' => ['target_id' => (int) ($in['client_type_id'] ?? 1)],
        'field_first_name' => $first,
        'field_last_name' => $last,
        'field_primary_contact_ref' => ['target_id' => $contact->id()],
      ]);
      if ($email !== '') {
        $profile->set('field_contact_email', $email);
      }
      if ($phone !== '') {
        $profile->set('field_main_phone_number', $phone);
      }
      $profile->save();

      // 7. Ownership record (client ↔ property).
      $ownership = $this->etm->getStorage('ownership_record')->create([
        'type' => 'record',
        'field_property_owner' => ['target_id' => $user->id()],
        'field_property_reference' => ['target_id' => $property->id()],
      ]);
      $ownership->save();

      // 8. Link the contact onto the property.
      $property->set('field_primary_contact_ref', ['target_id' => $contact->id()]);
      $property->set('field_contacts', ['target_id' => $contact->id()]);
      $property->save();

      unset($tx);
      return [
        'property' => $property,
        'user' => $user,
        'profile' => $profile,
        'contact' => $contact,
        'ownership' => $ownership,
      ];
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      throw $e;
    }
  }

  /**
   * A unique, valid username derived from the email or full name.
   */
  private function uniqueUsername(string $seed): string {
    $base = trim($seed) !== '' ? trim($seed) : 'customer';
    $storage = $this->etm->getStorage('user');
    $name = $base;
    $i = 1;
    while ($storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->range(0, 1)->execute()) {
      $name = $base . ' (' . (++$i) . ')';
    }
    return $name;
  }

}
