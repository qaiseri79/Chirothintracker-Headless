<?php

namespace Drupal\commerce_product_restriction\Plugin\Commerce\ProductRestriction;

use Drupal;
use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginBase;
use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides product restriction by user role.
 *
 * @ProductRestrictionPlugin(
 *   id = "password_protect",
 *   label = @Translation("Password protect"),
 *   category = @Translation("Security"),
 *   entity_types = {"commerce_product_variation", "commerce_product", },
 *   weight = -1
 * )
 */
class PasswordRestriction extends ProductRestrictionPluginBase implements ProductRestrictionPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'password' => '',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['commerce-product-restriction-password'] = [
      '#type' => 'textfield',
      '#title' => 'Password',
      '#required' => FALSE,
      '#default_value' => $this->configuration['password'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);
    $this->configuration['password'] = $values['commerce-product-restriction-password'];
  }

  /**
   * {@inheritdoc}
   */
  public function alterCartForm(array &$form, FormStateInterface $form_state) {
    $form_object = $form_state->getFormObject();
    if (!$form_object) {
      return;
    }

    if (get_class($form_object) == 'Drupal\views\Form\ViewsForm') {
      return;
    }

    if ($this->evaluate($form_object->getEntity())) {
      return;
    }

    $form['commerce-product-restriction-password'] = [
      '#type' => 'password',
      '#title' => t('Enter password for @label', ['@label' => $this->entity->label()]),
    ];

    $form_state->set('correct-password', $this->configuration['password']);
    $form_state->set('entity_type', $this->entity->getEntityTypeId());

    $form['actions']['submit-password'] = $form['actions']['submit'];
    $form['actions']['submit-password']['#submit'] = 'button';
    $form['actions']['submit-password']['#value'] = 'Check password';
    $form['actions']['submit-password']['#submit'] = [get_class($this) . '::alterCartFormSubmit'];

    $form['#validate'][] = get_class($this) . '::alterCartFormValidate';
  }

  /**
   * {@inheritdoc}
   */
  public static function alterCartFormValidate(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('commerce-product-restriction-password') != $form_state->get('correct-password')) {
      $form_state->setErrorByName('commerce-product-restriction-password', t('Password incorrect.'));
    }
    else {
      Drupal::messenger()
        ->addMessage('Correct password entered. You can now purchase the product.');

      /** @var \Drupal\commerce_order\Entity\OrderItem $order_item */
      $order_item = $form_state->getFormObject()->getEntity();
      $entity = $order_item->getPurchasedEntity();
      $key = static::getPasswordKey($entity);
      $_SESSION[$key] = $form_state->getValue('commerce-product-restriction-password');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function alterCartFormSubmit(array &$form, FormStateInterface $form_state) {
    $form_object = $form_state->getFormObject();
    if (!$form_object) {
      return;
    }
    if (get_class($form_object) == 'Drupal\views\Form\ViewsForm') {
      return;
    }

    $purchasedFromFormState = $form_state->getValue('purchased_entity');
    $selectedVariationId = $purchasedFromFormState[0]['variation'] ?: NULL;
    $selectedVariation = \Drupal\commerce_product\Entity\ProductVariation::load($selectedVariationId);
    if (!$selectedVariation) {
      $order_item = $form_state->getFormObject()->getEntity();
      $selectedVariation = $order_item->getPurchasedEntity();
    }

    if ($form_state->get('entity_type') == 'commerce_product') {
      $entity = $selectedVariation->getProduct();
    }
    else {
      $entity = $selectedVariation;
    }

    $key = static::getPasswordKey($entity);

    $_SESSION[$key] = $form_state->getValue('commerce-product-restriction-password');
  }

  /**
   * Get unique identifier for session variable.
   */
  public static function getPasswordKey($entity) {
    $key = '1commerce-product-restriction-password-' . $entity->getEntityTypeId() . '-' . $entity->id();
    return $key;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    if ($entity->getEntityTypeId() == 'commerce_order_item') {
      /** @var \Drupal\commerce_order\Entity\OrderItem $orderItem */
      $orderItem = $entity;
      $entity = $orderItem->getPurchasedEntity();
    }

    if ($this->configuration['password']) {
      $keys = [$this->getPasswordKey($entity)];

      foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && $_SESSION[$key] == $this->configuration['password']) {
          return TRUE;
        }
      }
    }
    else {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function accessErrorMessage($product_or_variation) {
    return new TranslatableMarkup(
      "You must enter the password to access this product (@label).",
      ['@label' => $product_or_variation->label()]
    );
  }

}
