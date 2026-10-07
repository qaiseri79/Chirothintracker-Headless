<?php

namespace Drupal\custom_module\Plugin\Commerce\Condition;

use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\commerce\Core\ConditionInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the 'Product owner user role' condition.
 *
 * @CommerceCondition(
 *   id = "product_owner_user_role",
 *   label = @Translation("Product owner user role"),
 *   display_label = @Translation("Product owner has specific user role"),
 *   category = @Translation("Product"),
 *   entity_type = "commerce_product",
 * )
 */
class ProductOwnerUserRole extends ConditionBase implements ConditionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ProductOwnerUserRole object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'roles' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $roles = user_role_names(TRUE);
    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#options' => $roles,
      '#default_value' => $this->configuration['roles'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['roles'] = array_filter($form_state->getValue('roles'));
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(OrderInterface $order = NULL) {
    if (!$this->configuration['roles']) {
      // The condition is not configured yet.
      return TRUE;
    }

    if ($order) {
      foreach ($order->getItems() as $order_item) {
        $product = $order_item->getPurchasedEntity();
        $product_owner = $product->getOwner();
        $product_owner_roles = $product_owner->getRoles();

        foreach ($this->configuration['roles'] as $role) {
          if (in_array($role, $product_owner_roles)) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function summary() {
    if (empty($this->configuration['roles'])) {
      return $this->t('Product owner has any role');
    }

    $roles = user_role_names();
    $selected_roles = array_intersect_key($roles, array_flip($this->configuration['roles']));
    return $this->t('Product owner has role(s): @roles', ['@roles' => implode(', ', $selected_roles)]);
  }
}