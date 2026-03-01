<?php

declare(strict_types=1);

namespace Drupal\estimate\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures a scope code is unique within a vocabulary (normalized).
 *
 * @Constraint(
 *   id = "ScopeCodeUnique",
 *   label = @Translation("Scope code unique within vocabulary", context = "Validation"),
 * )
 */
class ScopeCodeUniqueConstraint extends Constraint {

  public string $message = 'Scope code "%value" is already in use by another Service Scope Element. Scope codes must be unique (case/whitespace insensitive).';
  public string $vocabulary = 'service_scope_elements';
  public string $field = 'field_scope_code';

}
