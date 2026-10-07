<?php

namespace Drupal\commerce_product_restriction\Plugin;

use Drupal\commerce\Context;
use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A base class to help developers implement their own plugins.
 *
 * @see \Drupal\commerce_product_restriction\Annotation\ProductRestrictionPlugin
 * @see \Drupal\commerce_product_restriction\ProductRestrictionPluginInterface
 */
abstract class ProductRestrictionPluginBase extends ConditionBase implements PluginFormInterface, ProductRestrictionPluginInterface, ContainerFactoryPluginInterface {

  public Context $context;

  /**
   * Entity the plugin is restricting (product or variation).
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  public $entity;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * Set entity the plugin is restricting (product or variation).
   */
  public function setEntity(EntityInterface $entity) {
    $this->entity = $entity;
  }

  /**
   * Get entity the plugin is restricting (product or variation).
   */
  public function getEntity() {
    return $this->entity;
  }

  /**
   * Assert the entity (product or variation).
   */
  protected function assertEntity(EntityInterface $entity) {
    $entity_type_id = $entity->getEntityTypeId();
    if (!in_array($entity_type_id, [
      'commerce_product',
      'commerce_product_variation',
    ])) {
      throw new \InvalidArgumentException(sprintf('The restriction requires a product or product variation, but a "%s" entity was given.', $entity_type_id));
    }
  }

  /**
   * @inheritdoc
   */
  public function accessErrorMessage($product_or_variation) {
    $message = "You cannot purchase this product due to a product restriction rule. Contact us for more information.";

    return new TranslatableMarkup(
      "@message [@plugin]",
      [
        '@message' => $message,
        '@plugin' => $this->getLabel(),
      ]
    );
  }

  /**
   * Check if plugin applies to entity.
   */
  public function checkApplicable($product_or_variation) {
    if (isset($this->pluginDefinition['entity_types']) && count($this->pluginDefinition['entity_types']) > 0) {
      if (!in_array($product_or_variation->getEntityTypeId(), $this->pluginDefinition['entity_types'])) {
        return FALSE;
      }
    }

    if (isset($this->pluginDefinition['entity_bundles']) && count($this->pluginDefinition['entity_bundles']) > 0) {
      if (!in_array($product_or_variation->bundle(), $this->pluginDefinition['entity_bundles'])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Alter the cart form for the plugin's restriction.
   */
  public function alterCartForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Join a string with a natural language conjunction at the end.
   *
   * Https://gist.github.com/angry-dan/e01b8712d6538510dd9c.
   */
  public static function naturalLanguageJoin(array $list, $conjunction = 'and', $oxford_comma = TRUE) {
    $last = array_pop($list);
    if ($list) {
      return implode(', ', $list) . (count($list) > 1 && $oxford_comma ? ',' : '') . ' ' . $conjunction . ' ' . $last;
    }
    return $last;
  }

  /**
   * @param Context $context
   *
   * @return void
   */
  public function setContext(Context $context) {
    $this->context = $context;
  }

}
