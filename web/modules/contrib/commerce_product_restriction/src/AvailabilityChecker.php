<?php

namespace Drupal\commerce_product_restriction;

use Drupal;
use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_cart\OrderItemMatcherInterface;
use Drupal\commerce_order\AvailabilityResult;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\Entity\User;

/**
 * Availability checker.
 *
 * Modified from commerce_product_limits.
 */
class AvailabilityChecker {

  use StringTranslationTrait;

  /**
   * The order item matcher.
   *
   * @var \Drupal\commerce_cart\OrderItemMatcherInterface
   */
  protected $orderItemMatcher;

  /**
   * Constructs a new AvailabilityChecker object.
   *
   * @param \Drupal\commerce_cart\OrderItemMatcherInterface $order_item_matcher
   *   The order item matcher.
   */
  public function __construct(OrderItemMatcherInterface $order_item_matcher) {
    $this->orderItemMatcher = $order_item_matcher;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(OrderItemInterface $order_item) {
    $purchased_entity = $order_item->getPurchasedEntity();
    return $purchased_entity instanceof ProductVariationInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function checkVariation(PurchasableEntityInterface $entity, Context $context, $getFailingPlugins = FALSE) {
    /** @var \Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginManager $plugin_manager */
    $plugin_manager = Drupal::service('plugin.manager.product_restriction');
    $failingPlugins = [];

    // $requested_quantity = $this->getRequestedQuantity($order_item);
    // $entity = $order_item->getPurchasedEntity();
    // $logger = \Drupal::logger('commerce_product_restriction');
    // $logger->info('check: ' . $order_item->getTitle());
    // Get the conditions field(s) for this entity
    $restrictions = $this->getRestrictions($entity);

    $account = User::load($context->getCustomer()->id());
    // $logger->info('currentUser: ' . $account->getAccountName());
    // Loop through the plugins added and check if it passes:
    foreach ($restrictions as $entityType => $entityRestrictions) {
      foreach ($entityRestrictions as $restriction) {
        $plugin = $plugin_manager->createInstance($restriction['target_plugin_id'], $restriction['target_plugin_configuration']);

        if (!$plugin) {
          continue;
        }

        if ($entityType == 'commerce_product' && $entity->getEntityTypeId() == 'commerce_product_variation') {
          /** @var \Drupal\commerce_product\Entity\ProductVariation $entity */
          $entity = $entity->getProduct();
        }

        if (!$plugin->checkApplicable($entity)) {
          continue;
        }

        $plugin->setEntity($entity);
        $plugin->setContext($context);

        //        \Drupal::logger('commerce_product_restriction')->debug($restriction['target_plugin_id'] . ' evaluating ' . $entity->getEntityTypeId() . ' ID ' . $entity->id());
        $access = $plugin->evaluate($entity, $account);

        if (!$access && $account) {
          if ($getFailingPlugins) {
            if (!isset($failingPlugins[$entity->getEntityTypeId()])) {
              $failingPlugins[$entity->getEntityTypeId()] = [];
            }
            $failingPlugins[$entity->getEntityTypeId()][] = $plugin;
          }
          else {
            return AvailabilityResult::unavailable($plugin->accessErrorMessage($entity, $account));
          }
        }
      }
    }
    if ($getFailingPlugins) {
      return $failingPlugins;
    }
  }

  /**
   * Load the restrictions plugin field.
   */
  public function getRestrictions(PurchasableEntityInterface $variation) {
    $variation_type = $variation->bundle();
    $product = $variation->getProduct();
    $product_type = $product->bundle();

    $sources = [
      'commerce_product_variation' => $variation,
      'commerce_product' => $product,
    ];

    $restrictions = [];

    $entityFieldManager = Drupal::service('entity_field.manager');
    $fields_variation = $entityFieldManager->getFieldDefinitions('commerce_product_variation', $variation_type);
    $fields_product = $entityFieldManager->getFieldDefinitions('commerce_product', $product_type);
    $fieldsets = [$fields_variation, $fields_product];
    foreach ($fieldsets as $fieldset) {
      foreach ($fieldset as $field) {
        if ($field->getType() != 'commerce_plugin_item:product_restriction') {
          continue;
        }

        $entity = $sources[$field->getTargetEntityTypeId()];

        if (!isset($restrictions[$entity->getEntityTypeId()])) {
          $restrictions[$entity->getEntityTypeId()] = [];
        }

        $fieldData = $entity->get($field->getName());

        foreach ($fieldData->getValue() as $restriction) {
          $restrictions[$entity->getEntityTypeId()][] = $restriction;
        }
      }
    }

    // @todo sort by weight
    return $restrictions;
  }

  /**
   * Gets the "actual" requested quantity.
   *
   * (This logic checks if the requested
   * product is already in cart and adds the quantity in cart to the requested
   * quantity.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return int
   *   The requested quantity.
   */
  protected function getRequestedQuantity(OrderItemInterface $order_item) {
    $order = $order_item->getOrder();
    $requested_quantity = $order_item->getQuantity();
    // Add the quantity already in cart (if any), this assumes the order items
    // are combined.
    if ($order && $order_item->isNew()) {
      $matching_order_item = $this->orderItemMatcher->match($order_item, $order->getItems());
      if ($matching_order_item) {
        $requested_quantity = Calculator::add($matching_order_item->getQuantity(), $requested_quantity);
      }
    }

    return (int) $requested_quantity;
  }

}
