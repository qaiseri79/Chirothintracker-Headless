<?php

namespace Drupal\commerce_cart_redirection\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigManagerInterface;

/**
 * Class CommerceCartRedirectionConfigForm.
 */
class CommerceCartRedirectionConfigForm extends ConfigFormBase {

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;
  /**
   * Drupal\Core\Config\ConfigManagerInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected ConfigManagerInterface $configManager;

  /**
   * Drupal\Core\Entity\EntityTypeBundleInfo.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfo
   */
  protected EntityTypeBundleInfo $entityTypeBundleInfo;

  /**
   * CommerceCartRedirectionConfigForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   EntityTypeManagerInterface.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   ConfigManagerInterface.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfo $entity_type_bundle_info
   *   EntityTypeBundleInfo.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigManagerInterface $config_manager, EntityTypeBundleInfo $entity_type_bundle_info, ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configManager = $config_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    parent::__construct($config_factory, $typed_config);
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return \Drupal\commerce_cart_redirection\Form\CommerceCartRedirectionConfigForm|\Drupal\Core\Form\FormBase
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('config.factory'),
      $container->get(TypedConfigManagerInterface::class)
    );
  }


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_cart_redirection_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'commerce_cart_redirection.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // @NOTE due to an error in using commerce_product bundles in place of
    // commerce_product_variation bundles the config values are now misnamed
    // and should be fixed to be product_variation_bundles etc at some point.
    // Load all bundle types for commerce_product_variation.
    // @TODO This means the redirect won't work for entities that implement
    // purchasable but aren't commerce_product_variation(s) Fix?
    $config = $this->config('commerce_cart_redirection.settings');
    $bundle_options = [];
    $bundles = $this->entityTypeBundleInfo->getBundleInfo('commerce_product_variation');
    foreach ($bundles as $key => $bundle) {
      $bundle_options[$key] = $bundle['label'];
    }

    $form['product_variation_bundles'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Product variation bundles'),
    ];

    $form['product_variation_bundles']['product_bundles'] = [
      '#type' => 'checkboxes',
      '#description' => $this->t('Select the product variation bundles you want to be affected / not affected by the redirection'),
      '#weight' => '0',
      '#options' => $bundle_options,
      '#default_value' => $config->get('product_bundles') ?? [],
    ];

    $form['product_variation_bundles']['negate_product_bundles'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Negate the bundles condition'),
      '#description' => $this->t('Select whether you want the redirection to happen for all bundles except those chosen above'),
      '#default_value' => $config->get('negate_product_bundles') ?? FALSE,
      '#weight' => '0',
    ];

    $form['redirection_route'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Redirection route'),
    ];

    $path_options = [
      'checkout' => 'Checkout',
      'cart' => 'Cart',
      'other' => 'Other',
    ];
    $form['redirection_route']['redirection_route_path'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select the redirection route'),
      '#weight' => '10',
      '#options' => $path_options,
      '#default_value' => $config->get('redirection_route_path') ?? 'checkout',
    ];
    $form['redirection_route']['redirection_route_path_other'] = [
      '#type' => 'textfield',
      '#description' => $this->t('You can enter any url, local or remote. When entering your own you need to ensure it is valid & safe, there is no checking done by this module.'),
      '#weight' => '15',
      '#default_value' => $config->get('redirection_route_path_other') ?? '',
      '#states' => [
        'visible' => [
          '[name="redirection_route_path"]' => ['value' => 'other'],
        ],
        'required' => [
          '[name="redirection_route_path"]' => ['value' => 'other'],
        ],
      ],
    ];

    $form['advanced'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Advanced options'),
    ];

    $form['advanced']['clear_cart_before_add'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Clear cart before add'),
      '#description' => $this->t('Select whether everything that is currently in the cart will be removed before adding a new item'),
      '#default_value' => $config->get('clear_cart_before_add') ?? FALSE,
      '#weight' => '18',
    ];


    $form['add_to_cart_replacement_text'] = [
      '#type' => 'textfield',
      '#title' => 'Replacement "Add To Cart" button text.',
      '#description' => $this->t('Any text you enter here will replace the text on the default "Add to cart" button for Variations that will be redirected by this module.<br>Leave empty to use the default text.'),
      '#weight' => '20',
      '#default_value' => $config->get('add_to_cart_replacement_text') ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // If 'other' has been selected then we need to ensure that the redirection path was filled out.
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $values = $form_state->getValues();
    $this->config('commerce_cart_redirection.settings')
      ->set('product_bundles', $values['product_bundles'])
      ->set('negate_product_bundles', $values['negate_product_bundles'])
      ->set('redirection_route_path', $values['redirection_route_path'])
      ->set('redirection_route_path_other', $values['redirection_route_path_other'])
      ->set('add_to_cart_replacement_text', $values['add_to_cart_replacement_text'])
      ->set('clear_cart_before_add', $values['clear_cart_before_add'])
      ->save();
  }

}
