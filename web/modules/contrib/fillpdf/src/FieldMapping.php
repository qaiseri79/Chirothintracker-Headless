<?php

namespace Drupal\fillpdf;

/**
 * Represents a mapping between a PDF field and a merge value.
 *
 * Represents a mapping between a PDF field and a merge value (a value with
 * which to fill in the field). This is a bare-bones base class intended to be
 * subclassed and enhanced with additional properties and getter methods.
 *
 * FieldMapping objects are immutable; replace the value by calling the
 * constructor again if the value needs to change.
 */
abstract class FieldMapping {

  /**
   * The primary value of the mapping.
   *
   * @var string
   */
  protected $data;

  /**
   * Constructs a FieldMapping object.
   *
   * @param string $data
   *   String containing the data.
   */
  public function __construct($data) {
    $this->data = $data;
  }

  /**
   * Returns the data.
   *
   * @return string
   *   String containing the data.
   */
  public function getData() {
    return $this->data;
  }

}
