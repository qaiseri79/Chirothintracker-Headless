<?php

namespace Drupal\fillpdf\FieldMapping;

use Drupal\fillpdf\FieldMapping;

/**
 * Represents a mapping between a PDF text field and a merge value.
 *
 * TextFieldMapping objects are immutable; replace the value by calling the
 * constructor again if the value needs to change.
 */
final class TextFieldMapping extends FieldMapping {

  /**
   * Constructs an TextFieldMapping object.
   *
   * @param string $data
   *   String containing the text data.
   */
  public function __construct($data) {
    // Ensure data is a string.
    parent::__construct((string) $data);
  }

  /**
   * Return parent::getData().
   *
   * @return string
   *   The return value of parent::getData().
   */
  public function __toString() {
    return parent::getData();
  }

}
