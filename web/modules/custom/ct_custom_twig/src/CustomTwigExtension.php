<?php

namespace Drupal\ct_custom_twig;

use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_recurring\Entity\BillingScheduleInterface;

/**
 * Twig extension with some useful functions and filters.
 *
 * The extension consumes quite a lot of dependencies. Most of them are not used
 * on each page request. For performance reasons services are wrapped in static
 * callbacks.
 */
class CustomTwigExtension extends AbstractExtension
{

   /**
    * EntityTypeManager service.
    *
    * @var \Drupal\Core\Entity\EntityTypeManagerInterface
    */
   protected $entityTypeManager;

   /**
    * MyTwigExtension constructor.
    *
    * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
    *   EntityTypeManager service object.
    */
   public function __construct(EntityTypeManagerInterface $entityTypeManager)
   {
      $this->entityTypeManager = $entityTypeManager;
   }

   
  /**
   * Registers custom Twig functions.
   *
   * @return \Twig\TwigFunction[]
   *   An array of Twig functions.
   */
   public function getFunctions()
   {
      return [
         new TwigFunction('billing_schedule_label', [$this, 'getBillingScheduleLabel']),
      ];
   }

   /**
   * Returns the billing schedule label for a product variation ID.
   *
   * @param int $variation_id
   *   The product variation entity ID.
   *
   * @return string|null
   *   The billing schedule label, or NULL if not found or not applicable.
   */
  public function getBillingScheduleLabel($variation_id) {
    // Load the product variation entity by ID.
    $variation = $this->entityTypeManager
      ->getStorage('commerce_product_variation')
      ->load($variation_id);

    // Ensure the variation exists and is of the correct type.
    if (!$variation instanceof ProductVariationInterface) {
      return NULL;
    }

    // Check if the variation has the billing schedule field and it's not empty.
    // Replace 'billing_schedule' with the actual field name from your configuration.
    if (!$variation->hasField('billing_schedule') || $variation->get('billing_schedule')->isEmpty()) {
      return NULL;
    }

    // Load the referenced billing schedule entity.
    $billing_schedule = $variation->get('billing_schedule')->entity;

    // Return the label if the billing schedule is valid.
    if ($billing_schedule instanceof BillingScheduleInterface) {
      return $billing_schedule->label();
    }

    // Fallback return if something is missing.
    return NULL;
  }

}