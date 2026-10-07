<?php

namespace Drupal\hide_preview\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * This class renders and manages the configuration form of the module.
 *
 * @package Drupal\hide_preview\Form
 */
class HidePreviewConfigForm extends ConfigFormBase {

  const FORM_ID = 'hide_preview_config_form';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return self::FORM_ID;
  }

  /**
   * Builds and returns the configuration form.
   *
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('hide_preview.settings');

    $formNames = $config->get('hide_preview.form_names');

    if (empty($formNames)) {
      $formNames = '';
    }
    else {
      $formNames = implode(PHP_EOL, $formNames);
    }

    $form['form_names'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Form names'),
      '#default_value' => $formNames,
      '#description' => $this->t("<ul><li>Write only one form name per line</li>
        <li>Do not use comma as a separator</li>
        <li>Use either a form name as a string or a regular expression.<ul>
        <li>Check if the <i>form_id</i> begins with the pattern
        <i>contact_message_</i></li>
        <li>Check if the <i>form_id</i> matches the regexp
        <i>/contact_message_*/</i></li>
        </ul></ul>"
      ),
      '#required' => FALSE,
    ];
    return $form;
  }

  /**
   * Validates the users's input.
   *
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $formNames = $form_state->getValue('form_names');
    $formNames = $this->multiline2Array($formNames);

    foreach ($formNames as &$name) {
      $name = trim($name);
      preg_match('/[^\w]+/', $name, $matches);
      if (count($matches)) {
        if (@preg_match($name, '') === FALSE && preg_last_error() !== PREG_NO_ERROR) {
          $form_state->setErrorByName('form_names',
            $this->t('Form name "%name" contains non wordy characters and is
            not a regexp.',
              ['%name' => $name]
            )
          );
        }
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * Handles the post validation process.
   *
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $formNames = $form_state->getValue('form_names');
    $formNames = $this->multiline2Array($formNames);

    $config = $this->config('hide_preview.settings');
    $config->set('hide_preview.form_names', $formNames);

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get a string from a textarea and set every new line in an array.
   *
   * @param string $multiline
   *   A multi-line string from a textarea.
   *
   * @return array
   *   Returns all the lines of the string as an array.
   */
  public function multiline2Array($multiline = '') {
    $array = preg_split("/\r\n/", $multiline);
    $array = array_filter($array, [$this, 'emptyStringFilter']);

    return $array;
  }

  /**
   * Returns the editable config names.
   *
   * @return array
   *   Returns an array of the editable config names.
   */
  protected function getEditableConfigNames() {
    return [
      'hide_preview.settings',
    ];
  }

  /**
   * Filters empty and null values but not 0.
   *
   * @param mixed $value
   *   Array value to be filtered.
   *
   * @return bool
   *   Returns FALSE if the value must be filtered.
   */
  private function emptyStringFilter($value) {
    return ($value !== NULL && $value !== '');
  }

}
