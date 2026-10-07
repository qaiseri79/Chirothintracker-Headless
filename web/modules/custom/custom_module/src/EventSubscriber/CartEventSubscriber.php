<?php

namespace Drupal\custom_module\EventSubscriber;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates and limits what can be added to the cart.
 */
class CartEventSubscriber implements EventSubscriberInterface {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The cart manager.
   *
   * @var \Drupal\commerce_cart\CartManagerInterface
   */
  protected $cartManager;

  /**
   * Constructs a new CartEventSubscriber object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\commerce_cart\CartManagerInterface $cart_manager
   *   The cart manager.
   */
  public function __construct(MessengerInterface $messenger, CartManagerInterface $cart_manager) {
    $this->messenger = $messenger;
    $this->cartManager = $cart_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      CartEvents::CART_ENTITY_ADD => [['onProductAdded', 100]],
    ];
  }

  /**
   * Enforces cart rules based on product type.
   *
   * - subscription: only one allowed; removes everything else in the cart.
   * - chironutraceutical / default: multiples allowed, but any existing
   *   subscription is removed when one of these is added.
   *
   * @param \Drupal\commerce_cart\Event\CartEntityAddEvent $event
   *   The cart event.
   */
  public function onProductAdded(CartEntityAddEvent $event) {
    $order_item = $event->getOrderItem();
    $cart = $event->getCart();
    $added_type = $this->getProductType($order_item);

    foreach ($cart->getItems() as $item) {
      // Never touch the item that was just added.
      if ($item->id() === $order_item->id()) {
        continue;
      }

      $existing_type = $this->getProductType($item);

      if ($added_type === 'subscription') {
        // Subscription added → wipe everything else regardless of type.
        $this->cartManager->removeOrderItem($cart, $item);
      } elseif ($existing_type === 'subscription') {
        // Non-subscription added while a subscription is in the cart → remove
        // the subscription.
        $this->cartManager->removeOrderItem($cart, $item);
      }
      // Both items are non-subscription (chironutraceutical / default) →
      // leave them alone; multiple non-subscription products are allowed.
    }
  }

  /**
   * Returns the Commerce product type (bundle) for an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return string|null
   *   The product bundle machine name, or NULL if it cannot be determined.
   */
  protected function getProductType(OrderItemInterface $order_item): ?string {
    $purchased_entity = $order_item->getPurchasedEntity();

    if (!$purchased_entity instanceof ProductVariation) {
      return NULL;
    }

    $product = $purchased_entity->getProduct();
    return $product ? $product->bundle() : NULL;
  }
}
