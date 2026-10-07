<?php

namespace Drupal\viewfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\pager\None;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Viewfield Rendered Entities Formatter plugin definition.
 *
 * @FieldFormatter(
 *   id = "viewfield_rendered",
 *   label = @Translation("Rendered entities"),
 *   field_types = {"viewfield"}
 * )
 */
class ViewfieldFormatterRenderedEntities extends ViewfieldFormatterDefault {

  /**
   * Entity Type Manager to render each result item.
   *
   * @var EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $manager;

  /**
   * Display repository for gathering information about entity view modes.
   *
   * @var EntityDisplayRepositoryInterface
   */
  protected EntityDisplayRepositoryInterface $repository;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, EntityTypeManagerInterface $manager, EntityDisplayRepositoryInterface $repository) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->manager = $manager;
    $this->repository = $repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository')

    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return ['view_mode' => 'default'];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements['view_mode'] = [
      '#type' => 'select',
      '#options' => $this->getViewModeOptions(),
      '#title' => t('View mode'),
      '#default_value' => $this->getSetting('view_mode'),
      '#required' => TRUE,
    ];
    $elements['media_warning'] = [
      '#type' => 'container',
      '#markup' => $this->t('Note that entity access is handled by the View\'s configuration. There is no additional access checking before rendering the entities.'),
      '#attributes' => [
        'class' => ['messages messages--warning'],
      ],
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $options = $this->getViewModeOptions();
    return [$this->t('Rendered as @mode', ['@mode' => $options[$this->getSetting('view_mode')]])];
  }

  /**
   * Get all existing view modes for view_mode widget setting.
   *
   * Because this formatter might work against any entity type, a superset of
   * all view modes for all entity types is included in this list. Without
   * carefully coordination between this setting and the entity types used
   * in selected views, the resulting output could be unexpected.
   *
   * @return array
   *   A key => label array of view modes.
   */
  protected function getViewModeOptions() {
    $options = [];
    foreach ($this->repository->getAllViewModes() as $type_modes) {
      foreach ($type_modes as $mode => $info) {
        $options[$mode] = $info['label'];
      }
    }
    ksort($options);

    // Include "default" at the top of the list.
    return array_merge (['default' => ('Default')], $options);
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $entity = $items->getEntity();

    if ($this->getFieldSetting('force_default')) {
      $values = $this->fieldDefinition->getDefaultValue($entity);
    }
    else {
      $values = [];
      foreach ($items as $delta => $item) {
        $values[$delta] = $item->getValue();
      }
    }

    $elements = [];

    foreach ($values as $delta => $value) {

      if (!empty($value['target_id'])) {
        $target_id = $value['target_id'];
        $display_id = $value['display_id'] ?: 'default';
        $items_to_display = $value['items_to_display'];

        if (!empty($value['arguments'])) {
          $arguments = $this->processArguments($value['arguments'], $entity);
        }
        else {
          $arguments = [];
        }

        $view = Views::getView($target_id);
        if (!$view || !$view->access($display_id)) {
          continue;
        }

        // Set arguments if they exist
        if (!empty($arguments)) {
          $view->setArguments($arguments);
        }

        $view->setDisplay($display_id);

        // Override items to display if set.
        if(!empty($items_to_display)) {
          $view->setItemsPerPage($items_to_display);
        }

        $view->preExecute();
        $view->execute();

        // Collect cacheability of the view.
        $view_cacheability = $view->getDisplay()
          ->calculateCacheMetadata()
          ->addCacheTags($view->getCacheTags());
        $view_cacheability->applyTo($elements);

        // Disable pager, if items_to_display was set.
        if (!empty($items_to_display)) {
          $view->pager = new None([], '', []);
          $view->pager->init($view, $view->display_handler);
        }

        $rows = [];
        foreach ($view->result as $rid => $row) {
          if ($row->_entity->id() == $entity->id()) {
            continue;
          }
          $rows[$rid] = $row->_entity;
          if (!isset($view_builder)) {
            $view_builder = $this->manager->getViewBuilder($row->_entity->getEntityTypeId());
          }
        }
        if (isset($view_builder)) {
          foreach ($rows as $delta => $row) {
            // @todo: Render in a language that respects the View configuration rather than
            // just matching the language of the entity having this viewfield.
            $elements[$delta] = $view_builder->view($row, $this->getSetting('view_mode'), $entity->language()->getId());
            // We already applied this to $elements, but in practice we might
            // render each item separately, so let's add it to each item.
            $view_cacheability->applyTo($elements[$delta]);
          }
          $elements['#view_id'] = $target_id;
          $elements['#display_id'] = $display_id;
          $elements['#view_results'] = $rows;
        }
        return $elements;
      }
    }
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function view(FieldItemListInterface $items, $langcode = NULL) {
    $elements = parent::view($items, $langcode);
    // To fully "trick" the formatter into giving us the same output
    // as an entity reference field, we switch back from the `viewfield`
    // theme to the `field` theme and tell the render array to treat this
    // like a multivalued field. That way we get the field__item wrappers
    // that we want.
    if (isset($elements['#items'])) {
      $elements['#theme'] = 'field';
      $elements['#is_multiple'] = TRUE;
    }
    return $elements;
  }

  /**
   * This only makes sense for a single-value viewfield.
   *
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    return parent::isApplicable($field_definition) && $field_definition->getFieldStorageDefinition()->getCardinality() === 1;
  }

}
