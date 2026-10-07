<?php

namespace Drupal\commerce_shipping\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for resending order shipment confirmations.
 */
class ShipmentConfirmationResendForm extends ContentEntityConfirmFormBase {

  /**
   * The shipment confirmation mail service.
   *
   * @var \Drupal\commerce_shipping\Mail\ShipmentConfirmationMailInterface
   */
  protected $shipmentConfirmationMail;

  /**
   * The email validator.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  protected $emailValidator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->shipmentConfirmationMail = $container->get('commerce_shipping.shipment_confirmation_mail');
    $instance->emailValidator = $container->get('email.validator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to resend the shipment confirmation for %shipment for %order?', [
      '%shipment' => $this->entity->label(),
      '%order' => $this->entity->getOrder()->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $this->entity;
    $form['send_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Send to'),
      '#default_value' => $shipment->getOrder()->getEmail(),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $send_to = $form_state->getValue('send_to');
    if (!$this->emailValidator->isValid($send_to)) {
      $form_state->setErrorByName('send_to', $this->t('The entered email is not valid.'));
    }

    return parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Resend confirmation');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $this->entity;
    $send_to = $form_state->getValue('send_to');
    $result = $this->shipmentConfirmationMail->send($shipment, trim($send_to));
    // Drupal's MailManager sets an error message itself, if the sending failed.
    if ($result) {
      $this->messenger()->addMessage($this->t('Shipment confirmation resent.'));
    }
  }

}
