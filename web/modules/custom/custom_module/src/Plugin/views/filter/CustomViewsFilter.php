<?php

namespace Drupal\custom_module\Plugin\views\filter;

use Drupal\views\ViewExecutable;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\user\Entity\User;

/**
 *
 * @ViewsFilter("custom_views_filter")
 */
class CustomViewsFilter extends FilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $current_user = \Drupal::currentUser();
    $user_id = $current_user->id();
    if ($current_user->hasRole('chiropractor_active_')) {  
      $max_purchased_product_id = $this->getMaxPurchasedProductId($user_id);
      if ($max_purchased_product_id !== null) {
        $this->query->addWhere(0, 'commerce_product_field_data.product_id', $max_purchased_product_id, '>');
      }
    }else if ($current_user->hasRole('chiropractor_inactive_')) {  
          $max_purchased_product_id = $this->getMaxPurchasedProductId($user_id);
          if ($max_purchased_product_id !== null) {
            $this->query->addWhere(0, 'commerce_product_field_data.product_id', $max_purchased_product_id, '>=');
          }
    }
  }

  /**
   * Gets the maximum ID of products purchased by the user.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return int|null
   *   The maximum purchased product ID or null if none.
   */
  protected function getMaxPurchasedProductId($user_id) {
    $purchased_product_ids = [];

    $orders = \Drupal::entityTypeManager()->getStorage('commerce_order')->loadByProperties([
      'uid' => $user_id,
      'state' => 'completed',
    ]);
    foreach ($orders as $order) {
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      foreach ($order->getItems() as $order_item) {
        /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
        $purchased_product_ids[] = $order_item->getPurchasedEntity()->id();
      }
    }

    return !empty($purchased_product_ids) ? max($purchased_product_ids) : null;
  }
  /**
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    // No need to expose this filter to the UI.
  }

}
