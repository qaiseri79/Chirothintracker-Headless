<?php

namespace Drupal\xnumber\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures xnumber is in allowed range.
 *
 * @Constraint(
 *   id = "XnumberRange",
 *   label = @Translation("The value of xnumber.", context = "Validation")
 * )
 */
class XnumberRangeCostraint extends Constraint {

  /**
   * The number min.
   *
   * @var int|float|string|null
   */
  public $min;

  /**
   * The number max.
   *
   * @var int|float|string|null
   */
  public $max;

  /**
   * The number step scale.
   *
   * @var int|string|null
   */
  public $stepScale;

  /**
   * The number min message.
   *
   * @var string
   */
  public $minMessage;

  /**
   * The number max message.
   *
   * @var string
   */
  public $maxMessage;

}
