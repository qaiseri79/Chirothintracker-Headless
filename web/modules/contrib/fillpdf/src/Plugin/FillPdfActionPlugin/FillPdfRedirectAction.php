<?php

namespace Drupal\fillpdf\Plugin\FillPdfActionPlugin;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\fillpdf\OutputHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Action plugin redirecting to a generated PDF file saved to the filesystem.
 *
 * @package Drupal\fillpdf\Plugin\FillPdfActionPlugin
 *
 * @FillPdfActionPlugin(
 *   id = "redirect",
 *   label = @Translation("Redirect PDF to file")
 * )
 */
class FillPdfRedirectAction extends FillPdfSaveAction {

  /**
   * Constructs a \Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file_url_generator service.
   * @param \Drupal\fillpdf\OutputHandler $outputHandler
   *   The fillpdf.output_handler service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    OutputHandler $outputHandler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $outputHandler);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_url_generator'),
      $container->get('fillpdf.output_handler'),
    );
  }

  /**
   * Executes this plugin.
   *
   * Saves the PDF file to the filesystem and redirects to it.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirects user to the generated PDF file, or if saving the file fails,
   *   to the front page.
   */
  public function execute() {
    $saved_file = $this->savePdf();
    $url = ($saved_file !== FALSE) ? $this->fileUrlGenerator->generateAbsoluteString($saved_file->getFileUri()) : Url::fromRoute('<front>')->toString();
    return new RedirectResponse($url);
  }

}
