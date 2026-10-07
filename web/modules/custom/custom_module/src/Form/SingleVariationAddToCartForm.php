<?php

namespace Drupal\custom_module\Form;

use Drupal\commerce_cart\Form\AddToCartForm;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Add to cart form for an order item whose variation is already decided.
 *
 * Used on the patient products view, where each row already represents a
 * single, specific product variation (resolved via the view's relationship).
 * The default AddToCartForm falls back to a "select a variation" widget
 * whenever the product has variations of more than one type, which is
 * always the case here. Removing the purchased_entity widget keeps the
 * order item's pre-set variation and skips that fallback entirely.
 */
class SingleVariationAddToCartForm extends AddToCartForm {

  /**
   * {@inheritdoc}
   */
  public function setFormDisplay(EntityFormDisplayInterface $form_display, FormStateInterface $form_state) {
    $form_display = clone $form_display;
    $form_display->removeComponent('purchased_entity');
    $form_state->set('form_display', $form_display);
    return $this;
  }

}
