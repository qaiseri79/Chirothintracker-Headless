<?php

namespace Drupal\commerce_product_restriction\Plugin\Commerce\ProductRestriction;

use Drupal\commerce_product_restriction\Annotation\ProductRestrictionPlugin;
use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginBase;
use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\Entity\User;

/**
 * Provides product restriction by user role.
 *
 * @ProductRestrictionPlugin(
 *   id = "purchased_product",
 *   label = @Translation("Restrict to users who have purchased specific
 *   product(s)"), category = @Translation("Purchases"), entity_type =
 *   "commerce_product"
 * )
 */
class PurchasedProductRestriction extends ProductRestrictionPluginBase implements ProductRestrictionPluginInterface {

  use PurchasedProductTrait;

  /**
   * @var array Stores IDs of any entities we know the user has purchased,
   *   so we can check later in the plugin without re-querying.
   */
  public $purchasedEntities = [];

  /**
   * @return string
   *   Entity type ID of what the user must have purchased to pass this
   *   restriction
   */
  public function getEntityTypeId(): string {
    return 'commerce_product';
  }

  /**
   * @return \Drupal\Component\Render\FormattableMarkup|string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   Name of autocomplete box on form
   */
  public static function getElementTitle(): \Drupal\Component\Render\FormattableMarkup|string|TranslatableMarkup {
    return t('Products user must have purchased');
  }

  /**
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   Entity storage of the entity type the user must have purchased
   *   to pass this restriction
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getEntityStorage(): \Drupal\Core\Entity\EntityStorageInterface {
    return \Drupal::entityTypeManager()
      ->getStorage($this->getEntityTypeId());
  }

  /**
   * @return array
   *   Entities the user must have purchased to pass
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function loadEntities(): array {
    return $this->getEntityStorage()
      ->loadMultiple(array_column($this->configuration['ids'], 'target_id'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'ids' => [],
        'any_all' => 'any',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['ids'] = [
      '#type' => 'entity_autocomplete',
      '#title' => static::getElementTitle(),
      '#target_type' => $this->getEntityTypeId(),
      '#default_value' => $this->loadEntities() ?: NULL,
      '#selection_handler' => 'default:' . $this->getEntityTypeId(),
      '#tags' => TRUE,
      '#required' => TRUE,
    ];

    $form['any_all'] = [
      '#type' => 'select',
      '#title' => $this->t('Require any or all selected'),
      '#description' => $this->t('If multiple have been selected.'),
      '#options' => [
        'any' => 'Any',
        'all' => 'All',
      ],
      '#default_value' => $this->configuration['any_all'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);
    $this->configuration['ids'] = $values['ids'];
    $this->configuration['any_all'] = $values['any_all'];
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);

    $account = User::load(\Drupal::currentUser()->id());

    // We set ALL to TRUE by default, because if any entity fails we'll set it to false
    $all = TRUE;
    // We set ANY to FALSE by default, because if any entity meets the requirement we'll set it to true
    $any = FALSE;
    $entities = $this->loadEntities();
    foreach ($entities as $entity) {
      // Check if it's been purchased:
      if ($entity->getEntityTypeId() == 'commerce_product') {
        $count = $this->countUserPurchasedVariationOfProduct($account, $entity);
      }
      else {
        $count = $this->countUserPurchasedVariationOfProduct($account, $entity->getProduct(), [$entity->id()]);
      }

      // If purchased, ANY is met.
      if ($count > 0) {
        $any = TRUE;
        $this->purchasedEntities[] = $entity->id();
      }
      else {
        // If any one entity has not been purchased, ALL is failed.
        $all = FALSE;
      }
    }

    return $this->configuration['any_all'] == 'any' ? $any : $all;
  }

  /**
   * {@inheritdoc}
   */
  public function accessErrorMessage($product_or_variation) {
    $entities = $this->loadEntities();
    $labels = [];
    $entity_type_name_plural = NULL;
    
    // Get the labels of the entities and link to the products to help the user:
    foreach ($entities as $entity) {
      // Only tell them about things they need, to avoid confusion if they've already met some of the requirement:
      if (in_array($entity->id(), $this->purchasedEntities)) {
        continue;
      }

      $labels[] = new FormattableMarkup('<a href=":url" target="_blank">@label</a>', [
        ':url' => $entity->toUrl('canonical', ['absolute' => TRUE])
          ->toString(),
        '@label' => $entity->label(),
      ]);
      $entity_type_name_plural = $entity->getEntityType()->getPluralLabel();
    }

    // Message if only one required:
    if (count($labels) == 1) {
      return new TranslatableMarkup(
        "To purchase this, you must also have purchased @labels.",
        [
          '@labels' => new FormattableMarkup(implode(', ', $labels), []),
          '@entity_type_name_plural' => $entity_type_name_plural,
        ]
      );
    }

    // Message for any
    if ($this->configuration['any_all'] == 'any') {
      return new TranslatableMarkup(
        "To purchase this, you must have purchased at least one of these @entity_type_name_plural: @labels.",
        [
          '@labels' => new FormattableMarkup(implode(', ', $labels), []),
          '@entity_type_name_plural' => $entity_type_name_plural,
        ]
      );
    }
    else {
      // Message for all
      return new TranslatableMarkup(
        "To purchase this, you must have purchased all of these @entity_type_name_plural: @labels.",
        [
          '@labels' => new FormattableMarkup(implode(', ', $labels), []),
          '@entity_type_name_plural' => $entity_type_name_plural,
        ]
      );
    }
  }

}
