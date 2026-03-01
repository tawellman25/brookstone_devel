<?php

declare(strict_types=1);

namespace Drupal\estimate\Plugin\Validation\Constraint;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ScopeCodeUnique constraint.
 */
final class ScopeCodeUniqueConstraintValidator extends ConstraintValidator {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function validate($items, Constraint $constraint): void {
    if (!$constraint instanceof ScopeCodeUniqueConstraint) {
      return;
    }
    if ($items->isEmpty()) {
      return;
    }

    $raw = (string) ($items->value ?? '');
    $normalized = $this->normalize($raw);
    if ($normalized === '') {
      return;
    }

    $entity = $items->getEntity();
    if ($entity->getEntityTypeId() !== 'taxonomy_term') {
      return;
    }
    if ($entity->bundle() !== $constraint->vocabulary) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $candidates = [
      $raw,
      mb_strtoupper(trim($raw)),
      mb_strtolower(trim($raw)),
      trim($raw),
    ];
    $candidates = array_values(array_unique(array_filter($candidates, static fn($v) => $v !== '')));

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $constraint->vocabulary);

    if (!$entity->isNew()) {
      $query->condition('tid', (int) $entity->id(), '<>');
    }

    if (!empty($candidates)) {
      $group = $query->orConditionGroup();
      foreach ($candidates as $candidate) {
        $group->condition($constraint->field, $candidate);
      }
      $query->condition($group);
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return;
    }

    $terms = $storage->loadMultiple($ids);
    foreach ($terms as $term) {
      $other_raw = (string) ($term->get($constraint->field)->value ?? '');
      if ($this->normalize($other_raw) === $normalized) {
        $this->context->addViolation($constraint->message, ['%value' => $normalized]);
        return;
      }
    }
  }

  private function normalize(string $value): string {
    $value = trim($value);
    $value = mb_strtoupper($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return $value;
  }

}
