<?php

/**
 * @file
 * Contains \Drupal\Core\Validation\Plugin\Validation\Constraint\ValidReferenceConstraintValidator.
 */

namespace Drupal\Core\Validation\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Checks if referenced entities are valid.
 */
class ValidReferenceConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    $id = $value->get('target_id')->getValue();
    // '0' or NULL are considered valid empty references.
    if (empty($id)) {
      return;
    }
    $referenced_entity = $value->get('entity')->getTarget();
    if (!$referenced_entity) {
      $definition = $value->getDefinition();
      $type = $definition['settings']['target_type'];
      $this->context->addViolation($constraint->message, array('%type' => $type, '%id' => $id));
    }
  }
}
