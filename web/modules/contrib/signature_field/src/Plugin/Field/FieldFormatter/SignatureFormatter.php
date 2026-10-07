<?php

namespace Drupal\signature_field\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'field_signature_field_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "field_signature_field_formatter",
 *   module = "signature_field",
 *   label = @Translation("Signature formatter"),
 *   field_types = {
 *     "field_signature"
 *   }
 * )
 */
class SignatureFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => ['src' => $item->value],
      ];
    }
    return $elements;
  }

}
