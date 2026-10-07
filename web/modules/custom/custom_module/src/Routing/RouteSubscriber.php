<?php

namespace Drupal\custom_module\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds a clinic fulfillment-type access check to the variation add form.
 */
class RouteSubscriber extends RouteSubscriberBase
{

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection)
  {
    if ($route = $collection->get('entity.commerce_product_variation.add_form')) {
      $route->setRequirement('_custom_access', '\Drupal\custom_module\Access\VariationFulfillmentAccess::access');
    }

    // Require the specific "reassign commerce_order" permission. By default
    // this route also grants access via "administer commerce_order", which
    // lets roles like chiropractor_active_ reassign orders even though they
    // were never given the reassign permission itself.
    if ($route = $collection->get('entity.commerce_order.reassign_form')) {
      $route->setRequirement('_permission', 'reassign commerce_order');
    }
  }

}
