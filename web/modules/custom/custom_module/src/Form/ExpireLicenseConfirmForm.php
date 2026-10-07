<?php

namespace Drupal\custom_module\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\commerce_license\Entity\License;

class ExpireLicenseConfirmForm extends ConfirmFormBase {

  /**
   * The license entity.
   *
   * @var \Drupal\commerce_license\Entity\License
   */
  protected $license;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'expire_license_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to expire license %name?', ['%name' => $this->license->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('view.commerce_licenses.page_1');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Expire');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will mark the license as expired.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $commerce_license = NULL) {
    $this->license = License::load($commerce_license);
    if (!$this->license) {
      $this->messenger()->addError($this->t('License not found.'));
      $form_state->setRedirect('view.commerce_licenses.page_1');
      return [];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->license->set('state', 'expired');
    $this->license->save();
    $this->messenger()->addStatus($this->t('License %id marked as expired successfully.', ['%id' => $this->license->id()]));
    $form_state->setRedirect('view.commerce_licenses.page_1');
  }
}
