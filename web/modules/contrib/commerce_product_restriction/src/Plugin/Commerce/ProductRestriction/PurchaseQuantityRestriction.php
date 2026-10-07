<?php

namespace Drupal\commerce_product_restriction\Plugin\Commerce\ProductRestriction;

use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginBase;
use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\Entity\User;

/**
 * Provides product restriction by user role.
 *
 * @ProductRestrictionPlugin(
 *   id = "purchase_quantity_restriction",
 *   label = @Translation("Restrict quantity of purchase for product (across all variations in all user's orders)"),
 *   category = @Translation("User"),
 *   entity_type = "commerce_product"
 * )
 */
class PurchaseQuantityRestriction extends ProductRestrictionPluginBase implements ProductRestrictionPluginInterface {

  use PurchasedProductTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'uids' => [],
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['quantity'] = [
      '#type' => 'number',
      '#title' => 'Quantity customer can purchase (across all of their completed or processing orders)',
      '#default_value' => $this->configuration['quantity'] ?? 1,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);
    $this->configuration['quantity'] = $values['quantity'];
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);

    $account = User::load(\Drupal::currentUser()->id());

    if ($entity->getEntityTypeId() == 'commerce_product') {
      $count = $this->countUserPurchasedVariationOfProduct($account, $entity);
    }
    else {
      $count = $this->countUserPurchasedVariationOfProduct($account, $entity->getProduct());
    }

    return $count < intval($this->configuration['quantity']);
  }

  /**
   * {@inheritdoc}
   */
  public function accessErrorMessage($product_or_variation) {
    return new TranslatableMarkup(
      "You have already purchased the maximum quantity for this product (@quantity).",
      [
        '@quantity' => $this->configuration['quantity'],
      ]
    );
  }

}
