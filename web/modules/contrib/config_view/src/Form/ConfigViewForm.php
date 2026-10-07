<?php

namespace Drupal\config_view\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\CachedDiscoveryClearerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\views\ViewsData;

/**
 * Defines a base form for editing Config Entity Types.
 */
class ConfigViewForm extends ConfigFormBase {

  /**
   * The views' data service.
   *
   * @var \Drupal\views\ViewsData
   */
  protected $viewsData;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A plugin cache clear instance.
   *
   * @var \Drupal\Core\Plugin\CachedDiscoveryClearerInterface
   */
  protected $pluginCacheClearer;

  /**
   * Constructs a CommunityCoreSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\views\ViewsData $views_data
   *   The views data used to clear the views cache.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager.
   * @param \Drupal\Core\Plugin\CachedDiscoveryClearerInterface $plugin_cache_clearer
   *   A plugin cache clear instance.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ViewsData $views_data,
    EntityTypeManagerInterface $entity_type_manager,
    CachedDiscoveryClearerInterface $plugin_cache_clearer
  ) {
    $this->viewsData = $views_data;
    $this->entityTypeManager = $entity_type_manager;
    $this->pluginCacheClearer = $plugin_cache_clearer;
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('views.views_data'),
      $container->get('entity_type.manager'),
      $container->get('plugin.cache_clearer')
    );
  }

  /**
   * Gets the typed config manager.
   *
   * @return array
   *   Returns the all Config Entities.
   *
   * @see \Drupal::service('config.typed')
   */
  protected function getTypedConfig(): array {
    $types = $this->entityTypeManager->getDefinitions();
    $options = [];
    foreach ($types as $type) {
      if ($type->getGroup() === 'configuration') {
        $options[$type->id()] = (string) $type->getLabel();
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('config_view.settings')->get('data');
    $form = [];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Choose the configuration entities for which data needs to be exposed to the Views module.') . '</p>',
    ];

    $form['data'] = [
      '#type' => 'checkboxes',
      '#options' => $this->getTypedConfig(),
      '#default_value' => empty($config) ? $this->getTypedConfig() : $config,
      '#title' => $this->t('Configuration Entities:'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('config_view.settings');
    $config->set('data', array_filter($form_state->getValues()['data']))->save();
    parent::submitForm($form, $form_state);

    // Clear cache for views - only.
    $this->viewsData->clear();

    // Clear plugins cache.
    $this->pluginCacheClearer->clearCachedDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_view_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames(): array {
    return ['config_view.settings'];
  }

}
