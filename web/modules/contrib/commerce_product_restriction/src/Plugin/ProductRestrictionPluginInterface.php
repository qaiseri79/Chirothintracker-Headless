<?php

namespace Drupal\commerce_product_restriction\Plugin;

use Drupal\commerce\Plugin\Commerce\Condition\ConditionInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Interface for the ProductRestrictionPlugin.
 */
interface ProductRestrictionPluginInterface extends ConditionInterface {

  /**
   * Error to show user when they try to purchase the product.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *    The error message.
   */
  public function accessErrorMessage($product_or_variation);

  /**
   * Modify the add to cart form for the plugin.
   */
  public function alterCartForm(array &$form, FormStateInterface $form_state);

}
