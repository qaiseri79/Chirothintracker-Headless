<?php

namespace Drupal\xnumber\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures xnumber has a valid step.
 *
 * @Constraint(
 *   id = "XnumberValidStep",
 *   label = @Translation("The value of xnumber.", context = "Validation")
 * )
 */
class XnumberValidStepCostraint extends Constraint {

  /**
   * The number step.
   *
   * @var int|float|string|null
   */
  public $step;

  /**
   * The number min.
   *
   * @var int|float|string|null
   */
  public $min;

  /**
   * The number message.
   *
   * @var string
   */
  public $message;

}
