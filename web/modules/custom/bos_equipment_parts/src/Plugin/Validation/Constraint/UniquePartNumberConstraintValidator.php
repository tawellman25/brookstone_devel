<?php

declare(strict_types=1);

namespace Drupal\bos_equipment_parts\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the UniquePartNumber constraint.
 */
final class UniquePartNumberConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    if (!$items instanceof FieldItemListInterface || $items->isEmpty()) {
      return;
    }
    $raw = (string) $items->first()->value;
    $number = preg_replace('/\s+/u', ' ', trim($raw));
    if ($number === '') {
      return;
    }
    $entity = $items->getEntity();
    $storage = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId());
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($items->getFieldDefinition()->getName(), $number)
      ->range(0, 1);
    if (!$entity->isNew()) {
      $query->condition($entity->getEntityType()->getKey('id'), $entity->id(), '<>');
    }
    $ids = $query->execute();
    if (!$ids) {
      return;
    }
    // The storage compares with the column's case-insensitive collation; make
    // the same comparison explicit so the behaviour does not depend on it.
    $other = $storage->load(reset($ids));
    $stored = preg_replace('/\s+/u', ' ', trim((string) $other->get($items->getFieldDefinition()->getName())->value));
    if (mb_strtolower($stored) !== mb_strtolower($number)) {
      return;
    }
    $this->context->buildViolation($constraint->message)
      ->setParameter('%number', $number)
      ->setParameter('%title', $other->label())
      ->setParameter('%id', (string) $other->id())
      ->atPath('0.value')
      ->addViolation();
  }

}
