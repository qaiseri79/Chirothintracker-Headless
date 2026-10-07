<?php

namespace Drupal\views\Plugin\EntityReferenceSelection;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;
use Drupal\views\Render\ViewsRenderPipelineMarkup;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Plugin implementation of the 'selection' entity_reference.
 */
#[EntityReferenceSelection(
  id: "views",
  label: new TranslatableMarkup("Views: Filter by an entity reference view"),
  group: "views",
  weight: 0
)]
class ViewsSelection extends SelectionPluginBase implements ContainerFactoryPluginInterface {
  use StringTranslationTrait;

  /**
   * The loaded View object.
   *
   * @var \Drupal\views\ViewExecutable
   */
  protected $view;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs a new ViewsSelection object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Utility\Token $token
   *   The token replacement instance.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, AccountInterface $current_user, RendererInterface $renderer, Token $token) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->currentUser = $current_user;
    $this->renderer = $renderer;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('current_user'),
      $container->get('renderer'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'view' => [
        'view_name' => NULL,
        'display_name' => NULL,
        'arguments' => [],
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $view_settings = $this->getConfiguration()['view'];
    $displays = Views::getApplicableViews('entity_reference_display');
    // Filter views that list the entity type we want, and group the separate
    // displays by view.
    $entity_type = $this->entityTypeManager->getDefinition($this->configuration['target_type']);
    $view_storage = $this->entityTypeManager->getStorage('view');

    $options = [];
    foreach ($displays as $data) {
      [$view_id, $display_id] = $data;
      $view = $view_storage->load($view_id);
      if (in_array($view->get('base_table'), [$entity_type->getBaseTable(), $entity_type->getDataTable()])) {
        $display = $view->get('display');
        $options[$view_id . ':' . $display_id] = $view_id . ' - ' . $display[$display_id]['display_title'];
      }
    }

    // The value of the 'view_and_display' select below will need to be split
    // into 'view_name' and 'view_display' in the final submitted values, so
    // we massage the data at validate time on the wrapping element (not
    // ideal).
    $form['view']['#element_validate'] = [[static::class, 'settingsFormValidate']];

    if ($options) {
      $default = !empty($view_settings['view_name']) ? $view_settings['view_name'] . ':' . $view_settings['display_name'] : NULL;
      $form['view']['view_and_display'] = [
        '#type' => 'select',
        '#title' => $this->t('View used to select the entities'),
        '#required' => TRUE,
        '#options' => $options,
        '#default_value' => $default,
        '#description' => '<p>' . $this->t('Choose the view and display that select the entities that can be referenced.<br />Only views with a display of type "Entity Reference" are eligible.') . '</p>',
      ];

      $default = !empty($view_settings['arguments']) ? implode(', ', $view_settings['arguments']) : '';
      $form['view']['arguments'] = [
        '#type' => 'textfield',
        '#title' => $this->t('View arguments'),
        '#default_value' => $default,
        '#required' => FALSE,
        '#description' => $this->t('Provide a comma separated list of arguments to pass to the view.') . '<br />' . $this->t('This field supports tokens.'),
      ];
    }
    else {
      if ($this->currentUser->hasPermission('administer views') && $this->moduleHandler->moduleExists('views_ui')) {
        $form['view']['no_view_help'] = [
          '#markup' => '<p>' . $this->t('No eligible views were found. <a href=":create">Create a view</a> with an <em>Entity Reference</em> display, or add such a display to an <a href=":existing">existing view</a>.', [
            ':create' => Url::fromRoute('views_ui.add')->toString(),
            ':existing' => Url::fromRoute('entity.view.collection')->toString(),
          ]) . '</p>',
        ];
      }
      else {
        $form['view']['no_view_help']['#markup'] = '<p>' . $this->t('No eligible views were found.') . '</p>';
      }
    }
    return $form;
  }

  /**
   * Initializes a view.
   *
   * @param string|null $match
   *   (Optional) Text to match the label against. Defaults to NULL.
   * @param string $match_operator
   *   (Optional) The operation the matching should be done with. Defaults
   *   to "CONTAINS".
   * @param int $limit
   *   Limit the query to a given number of items. Defaults to 0, which
   *   indicates no limiting.
   * @param array|null $ids
   *   Array of entity IDs. Defaults to NULL.
   *
   * @return bool
   *   Return TRUE if the view was initialized, FALSE otherwise.
   */
  protected function initializeView($match = NULL, $match_operator = 'CONTAINS', $limit = 0, $ids = NULL) {
    $view_name = $this->getConfiguration()['view']['view_name'];
    $display_name = $this->getConfiguration()['view']['display_name'];

    // Check that the view is valid and the display still exists.
    $this->view = Views::getView($view_name);
    if (!$this->view || !$this->view->access($display_name)) {
      \Drupal::messenger()->addWarning($this->t('The reference view %view_name cannot be found.', ['%view_name' => $view_name]));
      return FALSE;
    }
    $this->view->setDisplay($display_name);

    // Pass options to the display handler to make them available later.
    $entity_reference_options = [
      'match' => $match,
      'match_operator' => $match_operator,
      'limit' => $limit,
      'ids' => $ids,
    ];
    $this->view->displayHandlers->get($display_name)->setOption('entity_reference_options', $entity_reference_options);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $entities = [];
    if ($display_execution_results = $this->getDisplayExecutionResults($match, $match_operator, $limit)) {
      $entities = $this->stripAdminAndAnchorTagsFromResults($display_execution_results);
    }
    return $entities;
  }

  /**
   * Fetches the results of executing the display.
   *
   * @param string|null $match
   *   (Optional) Text to match the label against. Defaults to NULL.
   * @param string $match_operator
   *   (Optional) The operation the matching should be done with. Defaults
   *   to "CONTAINS".
   * @param int $limit
   *   Limit the query to a given number of items. Defaults to 0, which
   *   indicates no limiting.
   * @param array|null $ids
   *   Array of entity IDs. Defaults to NULL.
   * @param bool $bubble_cacheable_metadata
   *   If TRUE the cacheability metadata emitted during token replacement will be
   *   bubbled to the render context. If FALSE then the it will not in order to
   *   prevent leaked cacheability metadata during early rendering.
   *
   * @return array
   *   The results.
   */
  protected function getDisplayExecutionResults(string $match = NULL, string $match_operator = 'CONTAINS', int $limit = 0, array $ids = NULL, $bubble_cacheable_metadata = TRUE): array {
    $display_name = $this->getConfiguration()['view']['display_name'];
    $arguments = $this->handleArgs($this->getConfiguration()['view']['arguments'], $bubble_cacheable_metadata);
    $results = [];
    if ($this->initializeView($match, $match_operator, $limit, $ids)) {
      $results = $this->view->executeDisplay($display_name, $arguments);
    }
    if (is_null($results)) {
      return (array) $results;
    }
    else {
      return $results;
    }
  }

  /**
   * Strips all admin and anchor tags from a result list.
   *
   * These results are usually displayed in an autocomplete field, which is
   * surrounded by anchor tags. Most tags are allowed inside anchor tags, except
   * for other anchor tags.
   *
   * @param array $results
   *   The result list.
   *
   * @return array
   *   The provided result list with anchor tags removed.
   */
  protected function stripAdminAndAnchorTagsFromResults(array $results) {
    $allowed_tags = Xss::getAdminTagList();
    if (($key = array_search('a', $allowed_tags)) !== FALSE) {
      unset($allowed_tags[$key]);
    }

    $stripped_results = [];
    foreach (Element::children($results) as $id) {
      $entity = $results[$id]['#row']->_entity;
      $stripped_results[$entity->bundle()][$id] = ViewsRenderPipelineMarkup::create(
        Xss::filter($this->renderer->renderInIsolation($results[$id]), $allowed_tags)
      );
    }

    return $stripped_results;
  }

  /**
   * {@inheritdoc}
   */
  public function countReferenceableEntities($match = NULL, $match_operator = 'CONTAINS') {
    $this->getReferenceableEntities($match, $match_operator);
    return $this->view->pager->getTotalItems();
  }

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    $entities = $this->getDisplayExecutionResults(NULL, 'CONTAINS', 0, $ids, FALSE);
    $result = [];
    if ($entities) {
      $result = array_keys($entities);
    }
    return $result;
  }

  /**
   * Element validate; Check View is valid.
   */
  public static function settingsFormValidate($element, FormStateInterface $form_state, $form) {
    // Split view name and display name from the 'view_and_display' value.
    if (!empty($element['view_and_display']['#value'])) {
      [$view, $display] = explode(':', $element['view_and_display']['#value']);
    }
    else {
      $form_state->setError($element, new TranslatableMarkup('The views entity selection mode requires a view.'));
      return;
    }

    // Explode the 'arguments' string into an actual array. Beware, explode()
    // turns an empty string into an array with one empty string. We'll need an
    // empty array instead.
    $arguments_string = trim($element['arguments']['#value']);
    if ($arguments_string === '') {
      $arguments = [];
    }
    else {
      // array_map() is called to trim whitespaces from the arguments.
      $arguments = array_map('trim', explode(',', $arguments_string));
    }

    $value = [
      'view_name' => $view,
      'display_name' => $display,
      'arguments' => $arguments,
    ];
    $form_state->setValueForElement($element, $value);
  }

  /**
   * Handles replacing tokens in arguments for views.
   *
   * Replaces tokens using Token::replace.
   *
   * @param array $args
   *   An array of arguments that may contain tokens.
   * @param bool $bubble_cacheable_metadata
   *   If TRUE the cacheability metadata emitted during token replacement will be
   *   bubbled to the render context. If FALSE then the it will not in order to
   *   prevent leaked cacheability metadata during early rendering.
   *
   * @return array
   *   The arguments to be sent to the View.
   */
  protected function handleArgs($args, $bubble_cacheable_metadata = TRUE) {
    $entities = [];
    if (isset($this->configuration['handler_settings']['entity'])) {
      $entities[] = $this->configuration['handler_settings']['entity'];
    }
    if (isset($this->configuration['entity'])) {
      $entities[] = $this->configuration['entity'];
    }
    if (isset($this->configuration['entity_info']) && !empty($this->configuration['entity_info']['id'])) {
      $entity = $this->entityTypeManager
        ->getStorage($this->configuration['entity_info']['type'])
        ->load($this->configuration['entity_info']['id']);
      if ($entity) {
        $entity->parent_group = $this->configuration['entity_info']['parent_group'] ?: NULL;
        $entities[] = $entity;
      }
    }
    if (isset($this->configuration['parent_view'])) {
      $view_config = $this->configuration['parent_view'];
      $parent_view = $this->entityTypeManager
        ->getStorage('view')
        ->load($view_config['id']);
      if (!$parent_view) {
        return $args;
      }
      $view_executable = \Drupal::service('views.executable')
        ->get($parent_view);

      $display_id = $view_config['display'];
      $parent_args = $view_config['args'];

      $view_executable->setDisplay($display_id);
      $view_executable->preExecute($parent_args);
      $view_executable->execute($display_id);
      $result = $view_executable->buildRenderable($display_id, $parent_args, FALSE);

      $delta = $view_config['delta'];
      $row = $result['#view']->result[$delta];
      if (!$row) {
        return $args;
      }
      $entities[] = $row->_entity;
      foreach ($row->_relationship_entities as $entity) {
        $entities[] = $entity;
      }
    }

    $data = [];
    foreach ($entities as $entity) {
      $token_type = $entity->getEntityTypeId();

      // Taxonomy term token type doesn't match the entity type's machine name.
      if ($token_type === 'taxonomy_term') {
        $token_type = 'term';
      }

      if (!isset($data[$token_type])) {
        $data[$token_type] = $entity;
      }
    }

    // If cacheability metadata should not be bubbled then we need to pass in
    // our own BubbleableMetadata which will prevent any metadata generated from
    // automatically bubbling to the render context.
    $bubbleable_metadata = $bubble_cacheable_metadata ? NULL : new BubbleableMetadata();

    // Replace tokens for each argument.
    foreach ($args as $key => $arg) {
      $value = $this->token->replace($arg, $data, ['clear' => TRUE], $bubbleable_metadata);
      $args[$key] = !empty($value) ? $value : NULL;
    }

    return $args;
  }

}
