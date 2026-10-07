<?php

namespace Drupal\migrate_file_to_media\Plugin\migrate\process;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\crop\Entity\Crop;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrateProcessInterface;
use Drupal\migrate\Plugin\migrate\process\FileCopy;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Copies or local file for usage in media module.
 *
 * Examples:
 *
 * @code
 * process:
 *   path_to_file:
 *     plugin: file_copy
 *     source:
 *       - id
 *       - public://new/path/to/
 * @endcode
 *
 * @see \Drupal\migrate\Plugin\MigrateProcessInterface
 *
 * @MigrateProcessPlugin(
 *   id = "media_file_copy"
 * )
 */
class MediaFileCopy extends FileCopy {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The file repository.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected FileRepositoryInterface $fileRepository;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, StreamWrapperManagerInterface $stream_wrappers, FileSystemInterface $file_system, MigrateProcessInterface $download_plugin, ModuleHandlerInterface $module_handler, FileRepositoryInterface $file_repository, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $stream_wrappers, $file_system, $download_plugin);
    $this->moduleHandler = $module_handler;
    $this->fileRepository = $file_repository;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('stream_wrapper_manager'),
      $container->get('file_system'),
      $container->get('plugin.manager.migrate.process')->createInstance('download', $configuration),
      $container->get('module_handler'),
      $container->get('file.repository'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($source_id, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // If we're stubbing a file entity, return a URI of NULL so it will get
    // stubbed by the general process.
    if ($row->isStub()) {
      return NULL;
    }

    $destination_folder = $this->configuration['path'] ?? 'public://media/';

    $destination = $destination_folder . $row->getSourceProperty('file_name');

    $source_file = FALSE;

    // If the source path or URI represents a remote resource, delegate to the
    // download plugin.
    if (!$this->isLocalUri($source_id)) {
      $source = $this->downloadPlugin->transform([
        $source_id,
        'public://download/' . $row->getSourceProperty('file_name'),
      ], $migrate_executable, $row, $destination_property);
    }
    else {
      $source_file = $this->entityTypeManager->getStorage('file')->load($source_id);
      $source = $source_file?->getFileUri();
    }

    if ($source_file) {
      $destination = $destination_folder . $source_file->getFilename();
    }

    // Ensure the source file exists, if it's a local URI or path.
    if (!file_exists($source)) {
      throw new MigrateException("File '$source' does not exist");
    }

    // Prepare destination folder.
    if (strpos($destination_folder, 'rokka') !== 0) {
      // Check if a writable directory exists, and if not try to create it.
      $dir = $this->getDirectory($destination);
      // If the directory exists and is writable, avoid file_prepare_directory()
      // call and write the file to destination.
      if (!$dir || !is_dir($dir) || !is_writable($dir)) {
        if (!$this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
          throw new MigrateException("Could not create or write to directory '$dir'");
        }
      }
    }

    $final_destination = $this->saveFile($source, $destination);
    if ($this->moduleHandler->moduleExists('crop')) {
      $this->updateFocalPoint($source, (string) $final_destination->getFileUri(), $final_destination);
    }
    return $final_destination->id();
  }

  /**
   * Save file to a defined destination.
   */
  protected function saveFile(string $source, string $destination, FileExists|int $replace = FileExists::Rename): FileInterface {
    $data = file_get_contents($source);
    if ($data === FALSE) {
      throw new MigrateException("File $source could not be read");
    }
    return $this->fileRepository->writeData($data, $destination, $replace);
  }

  /**
   * Update focal point.
   *
   * @param string $uri_old
   *   Old URI.
   * @param string $uri_rokka
   *   Rokka URI.
   * @param \Drupal\file\FileInterface $rokka_file
   *   Rokka file.
   *
   * @throws \Drupal\migrate\MigrateException
   */
  private function updateFocalPoint(string $uri_old, string $uri_rokka, FileInterface $rokka_file): void {

    try {

      /** @var \Drupal\crop\Entity\Crop|null $old_crop */
      $old_crop = Crop::findCrop($uri_old, 'focal_point');

      if (!empty($old_crop)) {

        $crop = Crop::create([
          'type' => 'focal_point',
          'entity_id' => $rokka_file->id(),
          'entity_type' => 'file',
          'uri' => $uri_rokka,
          'height' => $old_crop->height->value,
          'width' => $old_crop->width->value,
          'x' => $old_crop->x->value,
          'y' => $old_crop->y->value,
        ]);

        $crop->save();
      }

    }
    catch (\Exception $exception) {
      throw new MigrateException('Failed to save the focal point to rokka');
    }

  }

}
