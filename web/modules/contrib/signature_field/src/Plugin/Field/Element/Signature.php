<?php

namespace Drupal\signature_field\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;

/**
 * The Signature element.
 *
 * @FormElement("signature")
 */
class Signature extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#process' => [[$class, 'processSignature']],
      '#pre_render' => [[$class, 'preRenderSignature']],
      '#theme' => 'signature',
      '#theme_wrappers' => ['form_element'],
      '#attached' => [
        'library' => ['signature_field/signature_pad'],
      ],
      '#type' => 'textarea',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function processSignature(&$element, FormStateInterface $form_state, &$complete_form) {
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function preRenderSignature(array $element) {
    $sign_thumb = [
      '#type' => 'html_tag',
      '#tag' => 'img',
      '#attributes' => [
        'src' => '',
        'id' => 'signature-thumb',
        'class' => ['align-right'],
        'width' => '120px',
        'height' => '60px',
      ],
    ];
    $element['#suffix'] = \Drupal::service('renderer')->renderPlain($sign_thumb);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    return $element;
  }

}
