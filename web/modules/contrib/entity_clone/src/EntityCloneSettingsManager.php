<?php

namespace Drupal\entity_clone;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Manage entity clone configuration.
 */
class EntityCloneSettingsManager {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The immutable entity clone settings configuration entity.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The editable entity clone settings configuration entity.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $editableConfig;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * EntityCloneSettingsManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->config = $config_factory->get('entity_clone.settings');
    $this->editableConfig = $config_factory->getEditable('entity_clone.settings');
    $this->moduleHandler = $module_handler;
  }

  /**
   * Get all content entity types.
   *
   * @return \Drupal\Core\Entity\ContentEntityTypeInterface[]
   *   An array containing all content entity types.
   */
  public function getContentEntityTypes() {
    $definitions = $this->entityTypeManager->getDefinitions();
    $ret = [];
    foreach ($definitions as $machine => $type) {
      if ($type instanceof ContentEntityTypeInterface) {
        $ret[$machine] = $type;
      }
    }

    return $ret;
  }

  /**
   * Retrieves bundle information for a given entity type.
   *
   * @param string $entity_type_id
   *   The ID of the entity type.
   *
   * @return array
   *   An array of bundle information for the specified entity type.
   */
  public function getBundleInfo($entity_type_id) {
    return $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
  }

  /**
   * Set the entity clone settings.
   *
   * @param array $settings
   *   The settings from the form.
   */
  public function setFormSettings(array $settings) {
    if (isset($settings['table'])) {
      array_walk_recursive($settings['table'], function (&$item) {
        if ($item == '1') {
          $item = TRUE;
        }
        else {
          $item = FALSE;
        }
      });
      $form_settings = $this->config->get('form_settings');
      if ($form_settings) {
        $form_settings = array_merge($form_settings, $settings['table']);
        $this->editableConfig->set('form_settings', $form_settings)->save();
      }
      else {
        $this->editableConfig->set('form_settings', $settings['table'])->save();
      }
    }
  }

  /**
   * Get the checkbox default value for a given entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The content entity bundle.
   *
   * @return bool
   *   The default value.
   */
  public function getDefaultValue($entity_type_id, $bundle = NULL) {
    $form_settings = $this->config->get('form_settings');
    if (
      $this->getHandleBundlesSetting()
      && !empty($bundle)
      && isset($form_settings[$entity_type_id . ':' . $bundle]['default_value'])
    ) {
      return $form_settings[$entity_type_id . ':' . $bundle]['default_value'];
    }
    elseif (isset($form_settings[$entity_type_id]['default_value'])) {
      return $form_settings[$entity_type_id]['default_value'];
    }
    return FALSE;
  }

  /**
   * Get the checkbox disable value for a given entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The content entity bundles.
   *
   * @return bool
   *   The disable value.
   */
  public function getDisableValue($entity_type_id, $bundle = NULL) {
    $form_settings = $this->config->get('form_settings');
    if (
      $this->getHandleBundlesSetting()
      && !empty($bundle)
      && isset($form_settings[$entity_type_id . ':' . $bundle]['disable'])
    ) {
      return $form_settings[$entity_type_id . ':' . $bundle]['disable'];
    }
    elseif (isset($form_settings[$entity_type_id]['disable'])) {
      return $form_settings[$entity_type_id]['disable'];
    }
    return FALSE;
  }

  /**
   * Get the checkbox hidden value for a given entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The content entity bundle.
   *
   * @return bool
   *   The hidden value.
   */
  public function getHiddenValue($entity_type_id, $bundle = NULL) {
    $form_settings = $this->config->get('form_settings');
    if (
      $this->getHandleBundlesSetting()
      && !empty($bundle)
      && isset($form_settings[$entity_type_id . ':' . $bundle]['hidden'])
    ) {
      return $form_settings[$entity_type_id . ':' . $bundle]['hidden'];
    }
    elseif (isset($form_settings[$entity_type_id]['hidden'])) {
      return $form_settings[$entity_type_id]['hidden'];
    }
    return FALSE;
  }

  /**
   * Set the take ownership setting.
   *
   * @param int $setting
   *   The settings from the form.
   */
  public function setTakeOwnershipSettings(int $setting) {
    $this->editableConfig->set('take_ownership', $setting)->save();
  }

  /**
   * Get the take ownership settings.
   */
  public function getTakeOwnershipSetting() {
    return $this->config->get('take_ownership') ?? FALSE;
  }

  /**
   * Set the take ownership setting.
   *
   * @param int $setting
   *   The settings from the form.
   */
  public function setExcludeClonedSetting(int $setting) {
    $this->editableConfig->set('no_suffix', $setting)->save();
  }

  /**
   * Get the take ownership settings.
   */
  public function getExcludeClonedSetting() {
    return $this->config->get('no_suffix') ?? FALSE;
  }

  /**
   * Set the handle bundles setting.
   *
   * @param int $setting
   *   The settings from the form.
   */
  public function setHandleBundlesSetting(int $setting) {
    $this->editableConfig->set('handle_bundles', $setting)->save();
  }

  /**
   * Get the use bundles settings.
   */
  public function getHandleBundlesSetting() {
    return $this->config->get('handle_bundles') ?? FALSE;
  }

}
