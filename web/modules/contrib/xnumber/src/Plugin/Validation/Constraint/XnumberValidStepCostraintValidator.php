<?php

namespace Drupal\xnumber\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\xnumber\Utility\Xnumber as Numeric;

/**
 * Validates the XnumberValidStep constraint.
 */
class XnumberValidStepCostraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    if (!is_numeric($value)) {
      return;
    }
    if (!Numeric::validStep($value, $constraint->step, $constraint->min)) {
      $this->context->buildViolation($constraint->message)->addViolation();
    }
  }

}
