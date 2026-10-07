<?php

namespace Drupal\custom_module;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\custom_module\Form\SingleVariationAddToCartForm;

/**
 * Provides #lazy_builder callbacks for product variations.
 */
class ProductVariationLazyBuilders implements TrustedCallbackInterface {

  /**
   * Constructs a new ProductVariationLazyBuilders object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FormBuilderInterface $formBuilder,
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Builds an "Add to cart" form for a single, pre-selected variation.
   *
   * @param string $variation_id
   *   The product variation ID.
   * @param bool $combine
   *   TRUE to combine order items containing the same product variation.
   * @param string $langcode
   *   The langcode for the language that should be used in the form.
   *
   * @return array
   *   A renderable array containing the cart form.
   */
  public function addToCartForm($variation_id, $combine, $langcode) {
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variation_id);
    if (!$variation) {
      return [];
    }
    $variation = $this->entityRepository->getTranslationFromContext($variation, $langcode);

    /** @var \Drupal\commerce_order\OrderItemStorageInterface $order_item_storage */
    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_item = $order_item_storage->createFromPurchasableEntity($variation);

    /** @var \Drupal\custom_module\Form\SingleVariationAddToCartForm $form_object */
    $form_object = \Drupal::classResolver(SingleVariationAddToCartForm::class);
    $form_object->setStringTranslation(\Drupal::service('string_translation'));
    $form_object->setModuleHandler(\Drupal::service('module_handler'));
    $form_object->setEntityTypeManager($this->entityTypeManager);
    $form_object->setOperation('add_to_cart');
    $form_object->setEntity($order_item);
    $form_object->setFormId($form_object->getBaseFormId() . '_commerce_product_variation_' . $variation_id);

    $form_state = (new FormState())->setFormState([
      'product' => $variation->getProduct(),
      'view_mode' => 'default',
      'settings' => [
        'combine' => $combine,
      ],
    ]);

    return $this->formBuilder->buildForm($form_object, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['addToCartForm'];
  }

}
