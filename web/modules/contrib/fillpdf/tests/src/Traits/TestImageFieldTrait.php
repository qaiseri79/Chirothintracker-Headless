<?php

namespace Drupal\Tests\fillpdf\Traits;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides helper methods for creating Image fields.
 */
trait TestImageFieldTrait {

  /**
   * Create an entity with an image.
   *
   * This is our own version of ImageFieldTestBase::uploadNodeImage() only that
   * it supports creating fieldable entities other than nodes.
   *
   * @param \Drupal\file\Entity\File $image
   *   A file object representing the image to be added.
   * @param string $field_name
   *   Name of the image field the image should be attached to.
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $alt
   *   (optional) Alt text for the image. Use if required by field settings.
   *
   * @return int
   *   Entity ID of the created entity.
   *
   * @see \Drupal\Tests\image\Functional\ImageFieldTestBase::uploadNodeImage()
   */
  public function createImageFieldEntity(File $image, $field_name, $entity_type, $bundle, $alt = '') {
    if (empty($image->id())) {
      $image->save();
    }

    $type_manager = \Drupal::entityTypeManager();
    $definition = $type_manager->getDefinition($entity_type);

    $values = [];
    if ($bundle_key = $definition->getKey('bundle')) {
      $values[$bundle_key] = $bundle;
    }
    $label_key = $entity_type == 'user' ? 'name' : $definition->getKey('label');
    if ($label_key) {
      $values[$label_key] = $this->randomMachineName();
    }
    $values[$field_name] = [
      'target_id' => $image->id(),
      'alt' => $alt,
    ];

    $entity = $type_manager->getStorage($entity_type)->create($values);
    $entity->save();

    return $entity->id();
  }

  /**
   * Create a new image field.
   *
   * This is our own version of ImageFieldTestBase::createImageField()
   * only that it supports fieldable entity types other than nodes.
   *
   * @param string $field_name
   *   The name of the new field (all lowercase), exclude the "field_" prefix.
   * @param string $entity_type
   *   The entity type this field will be added to.
   * @param string $bundle
   *   The bundle this field will be added to.
   * @param array $storage_settings
   *   (optional) A list of field storage settings that will be added to the
   *   defaults.
   * @param array $field_settings
   *   (optional) A list of instance settings that will be added to the instance
   *   defaults.
   * @param array $widget_settings
   *   (optional) Widget settings to be added to the widget defaults.
   * @param array $formatter_settings
   *   (optional) Formatter settings to be added to the formatter defaults.
   * @param string $description
   *   (optional) A description for the field. Defaults to ''.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The field configuration.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   *
   * @see https://www.drupal.org/project/drupal/issues/3057070
   * @see \Drupal\Tests\image\Functional\ImageFieldTestBase::createImageField()
   * @todo Switch back to the original once the Core version works with other
   *   fieldable entities.
   */
  protected function createImageField($field_name, $entity_type, $bundle, array $storage_settings = [], array $field_settings = [], array $widget_settings = [], array $formatter_settings = [], $description = '') {
    if (!isset($this->container) || !$this->container instanceof ContainerInterface) {
      throw new \LogicException(__TRAIT__ . '::' . __METHOD__ . ' called without the container being available.');
    }

    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'image',
      'settings' => $storage_settings,
      'cardinality' => !empty($storage_settings['cardinality']) ? $storage_settings['cardinality'] : 1,
    ])->save();

    /** @var \Drupal\field\Entity\FieldConfig $field_config */
    $field_config = FieldConfig::create([
      'field_name' => $field_name,
      'label' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'required' => !empty($field_settings['required']),
      'settings' => $field_settings,
      'description' => $description,
    ]);
    $field_config->save();

    /** @var \Symfony\Component\DependencyInjection\ContainerInterface $container */
    $container = $this->container;

    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = $container->get('entity_type.manager');

    $form_values = [
      'targetEntityType' => $entity_type,
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
    ];
    $entity_form_display = $entity_type_manager->getStorage('entity_form_display');
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
    if (!$form_display = $entity_form_display->load("$entity_type.$bundle.default")) {
      $form_display = $entity_form_display
        ->create($form_values);
    }
    $form_display->setComponent($field_name, [
      'type' => 'image_image',
      'settings' => $widget_settings,
    ])
      ->save();

    $view_values = [
      'targetEntityType' => $entity_type,
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
    ];
    $entity_view_display = $entity_type_manager->getStorage('entity_view_display');
    /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display */
    if (!$display = $entity_view_display->load("$entity_type.$bundle.default")) {
      $display = $entity_view_display
        ->create($view_values);
    }
    $display->setComponent($field_name, [
      'type' => 'image',
      'settings' => $formatter_settings,
    ])
      ->save();

    return $field_config;
  }

}
