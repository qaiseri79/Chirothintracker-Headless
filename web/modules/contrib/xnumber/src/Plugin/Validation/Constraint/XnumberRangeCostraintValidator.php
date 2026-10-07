<?php

namespace Drupal\xnumber\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\xnumber\Utility\Xnumber as Numeric;

/**
 * Validates the XnumberRange constraint.
 */
class XnumberRangeCostraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    if (!is_numeric($value)) {
      return;
    }
    if ($constraint->stepScale !== NULL) {
      $value = Numeric::toString($value, $constraint->stepScale);
    }

    if ($constraint->min !== NULL) {
      $is_lower = $value < $constraint->min;
      if ($constraint->stepScale !== NULL) {
        $is_lower = bccomp($value, $constraint->min, $constraint->stepScale) < 0;
      }
      if ($is_lower) {
        $this->context->buildViolation($constraint->minMessage)->addViolation();
      }
    }
    if ($constraint->max !== NULL) {
      $is_greater = $value > $constraint->max;
      if ($constraint->stepScale !== NULL) {
        $is_greater = bccomp($value, $constraint->max, $constraint->stepScale) > 0;
      }
      if ($is_greater) {
        $this->context->buildViolation($constraint->maxMessage)->addViolation();
      }
    }
  }

}
