<?php

namespace Drupal\single_content_sync\Plugin\SingleContentSyncBaseFieldsProcessor;

use Drupal\book\BookManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\node\NodeInterface;
use Drupal\single_content_sync\ContentExporterInterface;
use Drupal\single_content_sync\ContentImporterInterface;
use Drupal\single_content_sync\SingleContentSyncBaseFieldsProcessorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation for node base fields processor plugin.
 *
 * @SingleContentSyncBaseFieldsProcessor(
 *   id = "node",
 *   label = @Translation("Node base fields processor"),
 *   entity_type = "node",
 * )
 */
class Node extends SingleContentSyncBaseFieldsProcessorPluginBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The content exporter.
   *
   * @var \Drupal\single_content_sync\ContentExporterInterface
   */
  protected ContentExporterInterface $exporter;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The content importer service.
   *
   * @var \Drupal\single_content_sync\ContentImporterInterface
   */
  protected ContentImporterInterface $importer;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * The book manager.
   *
   * @var \Drupal\book\BookManagerInterface|null
   */
  protected ?BookManagerInterface $bookManager;

  /**
   * A new instance of Node base fields processor plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\single_content_sync\ContentExporterInterface $exporter
   *   The content exporter service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\single_content_sync\ContentImporterInterface $importer
   *   The content importer service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\book\BookManagerInterface|null $book_manager
   *   The book manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager, ContentExporterInterface $exporter, AccountInterface $current_user, ConfigFactoryInterface $config_factory, ContentImporterInterface $importer, EntityRepositoryInterface $entity_repository, ?BookManagerInterface $book_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->exporter = $exporter;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->importer = $importer;
    $this->entityRepository = $entity_repository;
    $this->bookManager = $book_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('single_content_sync.exporter'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('single_content_sync.importer'),
      $container->get('entity.repository'),
      $container->get('book.manager', ContainerInterface::NULL_ON_INVALID_REFERENCE),
    );
  }

  /**
   * A handler to export a menu link referenced to node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to fetch link from.
   * @param array $base_fields
   *   The already exported base fields.
   */
  protected function exportMenuLink(NodeInterface $node, array &$base_fields): void {
    $export_mode = $this->configFactory->get('single_content_sync.settings')
      ->get('menu_link_export_mode') ?? 'stub';

    // Export is not applicable.
    if ($export_mode === 'none' || !$this->moduleHandler->moduleExists('menu_ui')) {
      return;
    }

    $menu_link = menu_ui_get_menu_link_defaults($node);
    $storage = $this->entityTypeManager->getStorage('menu_link_content');

    // Export content menu link item if available.
    if (!empty($menu_link['entity_id']) && ($menu_link_entity = $storage->load($menu_link['entity_id']))) {
      assert($menu_link_entity instanceof MenuLinkContentInterface);

      // Avoid infinitive loop, export menu link only once.
      if (!$this->exporter->isReferenceCached($menu_link_entity)) {

        // Use this flag later in the code, so it means that the referenced
        // entity needs to be partially exported.
        if ($export_mode === 'stub') {
          $menu_link_entity->setSyncing(TRUE);
        }

        $base_fields['menu_link'] = $this->exporter->doExportToArray($menu_link_entity);
      }
    }
  }

  /**
   * A handler to export a book structure referenced to node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to fetch link from.
   * @param array $base_fields
   *   The already exported base fields.
   */
  protected function exportBookStructure(NodeInterface $node, array &$base_fields): void {
    if (!$this->moduleHandler->moduleExists('book') || !isset($node->book)) {
      return;
    }

    $base_fields['book'] = $node->book;

    // We don't need this as this is the data that is populated on node_load.
    unset($base_fields['book']['link_path']);
    unset($base_fields['book']['link_title']);

    // We already exported itself, just use uuid here.
    $base_fields['book']['nid'] = $node->uuid();
    $storage = $this->entityTypeManager->getStorage('node');

    if ($node->book['bid'] === $node->book['nid']) {
      // We already exported itself, just use uuid here.
      $base_fields['book']['bid'] = $node->uuid();
    }
    else {
      $book = $storage->load($node->book['bid']);
      assert($book instanceof NodeInterface);
      $base_fields['book']['bid'] = [
        'uuid' => $book->uuid(),
        'entity_type' => $book->getEntityTypeId(),
        'base_fields' => $this->exporter->exportBaseValues($book),
        'bundle' => $book->bundle(),
      ];
    }

    if ($node->book['pid'] !== '0') {
      $parent = $storage->load($node->book['pid']);
      assert($parent instanceof NodeInterface);
      $base_fields['book']['pid'] = [
        'uuid' => $parent->uuid(),
        'entity_type' => $parent->getEntityTypeId(),
        'base_fields' => $this->exporter->exportBaseValues($parent),
        'bundle' => $parent->bundle(),
      ];
    }

    for ($i = 1; $i < 10; $i++) {
      if ($node->book["p{$i}"] === '0') {
        continue;
      }

      if ($node->book["p{$i}"] === $node->book['nid']) {
        $base_fields['book']["p{$i}"] = $node->uuid();
        continue;
      }

      if ($node->book["p{$i}"] === $node->book['bid']) {
        $base_fields['book']["p{$i}"] = $base_fields['book']['bid']['uuid'];
        continue;
      }

      if ($node->book["p{$i}"] === $node->book['pid']) {
        $base_fields['book']["p{$i}"] = $base_fields['book']['pid']['uuid'];
        continue;
      }

      $parent = $storage->load($node->book["p{$i}"]);
      assert($parent instanceof NodeInterface);
      $base_fields['book']["p{$i}"] = [
        'uuid' => $parent->uuid(),
        'entity_type' => $parent->getEntityTypeId(),
        'base_fields' => $this->exporter->exportBaseValues($parent),
        'bundle' => $parent->bundle(),
      ];
    }
  }

  /**
   * Import book structure.
   *
   * @param array $values
   *   The exported values to import.
   * @param \Drupal\node\NodeInterface $node
   *   The node entity being imported.
   */
  protected function importBookStructure(array $values, NodeInterface $node): void {
    if (!$this->moduleHandler->moduleExists('book') || !isset($values['book'])) {
      return;
    }

    if (!$this->bookManager) {
      // This should never happen if the module is enabled, but we've declared
      // the property as nullable so there's a safety check here.
      throw new \Exception('The book.manager service is not available.');
    }

    $is_new = $node->isNew();

    if ($is_new) {
      $node->save();
    }

    $values['book']['nid'] = (int) $node->id();

    // Import book.
    $book_uuid = $values['book']['bid']['uuid'] ?? $values['book']['bid'];
    $book = $this
      ->entityRepository
      ->loadEntityByUuid('node', $book_uuid);

    // Create a stub entity without custom field values.
    if (!$book) {
      $book = $this->importer->createStubEntity($values['book']['bid']);
    }

    $values['book']['bid'] = (string) $book->id();

    // Import parent.
    $parent_uuid = $values['book']['pid']['uuid'] ?? $values['book']['pid'];

    if ($parent_uuid !== '0') {
      $parent = $this
        ->entityRepository
        ->loadEntityByUuid('node', $parent_uuid);

      // Create a stub entity without custom field values.
      if (!$parent) {
        $parent = $this->importer->createStubEntity($values['book']['pid']);
      }

      $values['book']['pid'] = (string) $parent->id();
    }

    for ($i = 1; $i < 10; $i++) {
      if ($values['book']["p{$i}"] === '0') {
        continue;
      }

      if ($values['book']["p{$i}"] === $node->uuid()) {
        $values['book']["p{$i}"] = (string) $node->id();
        continue;
      }

      if ($values['book']["p{$i}"] === $book->uuid()) {
        $values['book']["p{$i}"] = (string) $book->id();
        continue;
      }

      if ($values['book']["p{$i}"] === $parent->uuid()) {
        $values['book']["p{$i}"] = (string) $parent->id();
        continue;
      }

      $p_uuid = $values['book']["p{$i}"]['uuid'] ?? $values['book']["p{$i}"];
      $p_node = $this
        ->entityRepository
        ->loadEntityByUuid('node', $p_uuid);

      // Create a stub entity without custom field values.
      if (!$p_node) {
        $p_node = $this->importer->createStubEntity($values['book']["p{$i}"]);
      }

      $values['book']["p{$i}"] = (string) $p_node->id();
    }

    $this->bookManager->saveBookLink($values['book'], $is_new);
  }

  /**
   * {@inheritdoc}
   */
  public function exportBaseValues(FieldableEntityInterface $entity): array {
    assert($entity instanceof NodeInterface);

    $owner = $entity->getOwner();

    $base_fields = [
      'title' => $entity->getTitle(),
      'status' => $entity->isPublished(),
      'langcode' => $entity->language()->getId(),
      'created' => $entity->getCreatedTime(),
      'author' => $owner ? $owner->getEmail() : NULL,
      'revision_log_message' => $entity->getRevisionLogMessage(),
      'revision_uid' => $entity->getRevisionUserId(),
    ];

    if ($this->moduleHandler->moduleExists('publication_date') && $entity->hasField('published_at')) {
      $base_fields['published_at'] = $entity->get('published_at')->value;
    }

    $this->exportMenuLink($entity, $base_fields);
    $this->exportBookStructure($entity, $base_fields);

    return $base_fields;
  }

  /**
   * {@inheritdoc}
   */
  public function mapBaseFieldsValues(array $values, FieldableEntityInterface $entity): array {
    assert($entity instanceof NodeInterface);
    $baseFields = [
      'title' => $values['title'],
      'langcode' => $values['langcode'],
      'created' => $values['created'],
      'status' => $values['status'],
    ];

    if (!empty($values['published_at'])) {
      $baseFields['published_at'] = $values['published_at'];
    }

    // Load node author.
    $account_provided = !empty($values['author']);
    $account = $account_provided
    ? user_load_by_mail($values['author'])
    : NULL;

    if ($account) {
      $baseFields['uid'] = $account->id();
    }

    // Adjust revision if the author is not available.
    if (!$account_provided || !$account) {
      $log_extra = "\n" . $this->t('Original Author: @author', [
        '@author' => $account_provided ? $values['author'] : $this->t('Unknown'),
      ]);

      if (!empty($values['revision_log_message'])) {
        $entity->setRevisionLogMessage($values['revision_log_message'] . $log_extra);
      }

      if ($this->currentUser->isAuthenticated()) {
        $baseFields['uid'] = $this->currentUser->id();
      }
    }

    return $baseFields;
  }

  /**
   * {@inheritdoc}
   */
  public function afterBaseValuesImport(array $values, FieldableEntityInterface $entity): void {
    assert($entity instanceof NodeInterface);
    $this->importBookStructure($values, $entity);
  }

}
