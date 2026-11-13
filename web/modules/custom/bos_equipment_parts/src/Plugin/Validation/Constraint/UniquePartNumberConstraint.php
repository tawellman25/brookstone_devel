<?php

declare(strict_types=1);

namespace Drupal\bos_equipment_parts\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * One catalog row per part number, compared the way the shop reads them.
 *
 * Core's UniqueField compares the raw string strictly, so "cmr6h " would sit
 * beside "CMR6H" as a second row. Here the value is normalised the same way
 * bos_equipment_parts_equipment_part_presave() stores it (trimmed, single
 * internal spaces) and compared case-insensitively against the stored
 * numbers, excluding the part being edited.
 */
#[Constraint(
  id: 'UniquePartNumber',
  label: new TranslatableMarkup('Unique equipment part number', [], ['context' => 'Validation']),
  type: ['string']
)]
final class UniquePartNumberConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   *
   * @var string
   */
  public string $message = 'Part number %number is already used by %title (part #%id). Edit that part instead of adding a second row — stock counts and "parts used" must point at one record.';

}
