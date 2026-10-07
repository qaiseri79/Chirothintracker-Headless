<?php

namespace Drupal\commerce_product_restriction\Annotation;

use Drupal\commerce\Annotation\CommerceCondition;

/**
 * Defines a ProductRestrictionPlugin annotation object.
 *
 * Note that the "@ Annotation" line below is required and should be the last
 * line in the docblock. It's used for discovery of Annotation definitions.
 *
 * @see \Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginManager
 * @see plugin_api
 *
 * @Annotation
 */
class ProductRestrictionPlugin extends CommerceCondition {

  /**
   * Bundles of products or product variations to which the condition can apply.
   *
   * @var array
   */
  public $entity_types = [];

  /**
   * Bundles of products or product variations to which the condition can apply.
   *
   * @var array
   */
  public $entity_bundles = [];

  /**
   * Description.
   *
   * @var string
   */
  public $description = [];

}
