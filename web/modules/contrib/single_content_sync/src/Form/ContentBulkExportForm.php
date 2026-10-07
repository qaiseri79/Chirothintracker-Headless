<?php

namespace Drupal\single_content_sync\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\single_content_sync\ContentFileGeneratorInterface;
use Drupal\single_content_sync\Utility\CommandHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Defines a form to export multiple content entities.
 */
class ContentBulkExportForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The command helper.
   *
   * @var \Drupal\single_content_sync\Utility\CommandHelperInterface
   */
  protected CommandHelperInterface $commandHelper;

  /**
   * The content file generator.
   *
   * @var \Drupal\single_content_sync\ContentFileGeneratorInterface
   */
  protected ContentFileGeneratorInterface $fileGenerator;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $singleContentSyncConfigFactory;

  /**
   * Constructs a ContentBulkExportForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\single_content_sync\Utility\CommandHelperInterface $command_helper
   *   The command helper.
   * @param \Drupal\single_content_sync\ContentFileGeneratorInterface $file_generator
   *   The content file generator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    CommandHelperInterface $command_helper,
    ContentFileGeneratorInterface $file_generator,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->commandHelper = $command_helper;
    $this->fileGenerator = $file_generator;
    $this->singleContentSyncConfigFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('single_content_sync.command_helper'),
      $container->get('single_content_sync.file_generator'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'single_content_sync_bulk_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $entity_type_options = $this->getEntityTypeOptions();
    $selected_entity_type = $this->getSelectedEntityType($form_state, $entity_type_options);

    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $entity_type_options,
      '#default_value' => $selected_entity_type,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::refreshBundles',
        'wrapper' => 'single-content-sync-bundle-wrapper',
      ],
    ];

    $form['bundle_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'single-content-sync-bundle-wrapper',
      ],
    ];

    $bundle_options = $this->getBundleOptions($selected_entity_type);
    $selected_bundle = $form_state->getValue('bundle')
      ?? $this->getRequest()->query->get('bundle', '');
    if (!isset($bundle_options[$selected_bundle])) {
      $selected_bundle = '';
    }

    $form['bundle_wrapper']['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#options' => $bundle_options,
      '#default_value' => $selected_bundle,
    ];

    $form['translation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include all translations'),
      '#description' => $this->t('Whether to export available translations of the content.'),
      '#default_value' => FALSE,
    ];

    $form['assets'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include all assets'),
      '#description' => $this->t('Whether to export all file assets such as images, documents, videos and etc.'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Export'),
    ];

    return $form;
  }

  /**
   * Ajax callback to refresh bundle options.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The refreshed bundle element wrapper.
   */
  public function refreshBundles(array &$form, FormStateInterface $form_state): array {
    return $form['bundle_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $entity_type_options = $this->getEntityTypeOptions();
    $entity_type_id = $form_state->getValue('entity_type');
    if (!isset($entity_type_options[$entity_type_id])) {
      $form_state->setErrorByName('entity_type', $this->t('Select an entity type that can be exported.'));
      return;
    }

    $bundle = $form_state->getValue('bundle', '');
    $bundle_options = $this->getBundleOptions($entity_type_id);
    if (!isset($bundle_options[$bundle])) {
      $form_state->setErrorByName('bundle', $this->t('Select a valid bundle.'));
      return;
    }

    if (!$this->commandHelper->getEntitiesToExport($entity_type_id, $bundle)) {
      $form_state->setErrorByName('entity_type', $this->t('Nothing to export. Please check if content exists and is allowed to be exported in the module configuration.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity_type_id = $form_state->getValue('entity_type');
    $bundle = $form_state->getValue('bundle', '');
    $include_translations = (bool) $form_state->getValue('translation', FALSE);
    $include_assets = (bool) $form_state->getValue('assets', FALSE);
    $entities = $this->commandHelper->getEntitiesToExport($entity_type_id, $bundle);
    $file = $this->fileGenerator->generateBulkZipFile($entities, $include_translations, $include_assets);

    $response = new StreamedResponse(static function() use ($file) {
      $fp = fopen($file->getFileUri(), 'rb');

      while (!feof($fp)) {
        echo fread($fp, 8192);
        flush();
      }

      fclose($fp);
      $file->delete();
    }, 200, [
      'Content-disposition' => 'attachment; filename="' . $file->getFilename() . '"',
      'Content-Type' => 'application/zip',
    ]);

    $form_state->setResponse($response);
  }

  /**
   * Checks access to the bulk export form.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(): AccessResultInterface {
    return AccessResult::allowedIf(!empty($this->getEntityTypeOptions()))
      ->cachePerPermissions()
      ->addCacheTags(['config:single_content_sync.settings']);
  }

  /**
   * Gets available entity type options.
   *
   * @return array
   *   Entity type labels keyed by entity type ID.
   */
  protected function getEntityTypeOptions(): array {
    $options = [];
    $allowed_entity_types = $this->singleContentSyncConfigFactory->get('single_content_sync.settings')->get('allowed_entity_types') ?? [];

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!array_key_exists($entity_type_id, $allowed_entity_types)
        || !$this->isExportableEntityType($entity_type)) {
        continue;
      }

      if (!$this->currentUser()->hasPermission('export single content')
        && !$this->currentUser()->hasPermission("export {$entity_type_id} content")) {
        continue;
      }

      $options[$entity_type_id] = (string) $entity_type->getLabel();
    }

    natcasesort($options);
    return $options;
  }

  /**
   * Gets the selected entity type ID.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $entity_type_options
   *   Entity type labels keyed by entity type ID.
   *
   * @return string|null
   *   The selected entity type ID.
   */
  protected function getSelectedEntityType(FormStateInterface $form_state, array $entity_type_options): ?string {
    $selected_entity_type = $form_state->getValue('entity_type')
      ?: $this->getRequest()->query->get('entity_type');

    if ($selected_entity_type && isset($entity_type_options[$selected_entity_type])) {
      return $selected_entity_type;
    }

    return array_key_first($entity_type_options);
  }

  /**
   * Gets bundle options for an entity type.
   *
   * @param string|null $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   Bundle labels keyed by bundle ID.
   */
  protected function getBundleOptions(?string $entity_type_id): array {
    $options = [
      '' => $this->t('- All bundles -'),
    ];

    if (!$entity_type_id) {
      return $options;
    }

    $allowed_bundles = $this->singleContentSyncConfigFactory
      ->get('single_content_sync.settings')
      ->get('allowed_entity_types')[$entity_type_id] ?? [];
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);

    foreach ($bundles as $bundle_id => $bundle_info) {
      if ($allowed_bundles && !in_array($bundle_id, $allowed_bundles, TRUE)) {
        continue;
      }

      $options[$bundle_id] = $bundle_info['label'];
    }

    return $options;
  }

  /**
   * Determines whether an entity type can be exported.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   *
   * @return bool
   *   TRUE if the entity type can be exported.
   */
  protected function isExportableEntityType(EntityTypeInterface $entity_type): bool {
    return $entity_type->hasLinkTemplate('single-content:export');
  }

}
