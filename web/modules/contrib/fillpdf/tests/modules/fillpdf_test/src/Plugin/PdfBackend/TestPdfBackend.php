<?php

namespace Drupal\fillpdf_test\Plugin\PdfBackend;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\fillpdf\Plugin\PdfBackendBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Backend used in tests.
 *
 * @PdfBackend(
 *   id = "test",
 *   label = @Translation("Pass-through plugin for testing")
 * )
 */
class TestPdfBackend extends PdfBackendBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a LocalFillPdfBackend plugin object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Extension\ModuleExtensionList $extensionListModule
   *   The extension.list.module service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected ModuleExtensionList $extensionListModule,
    protected StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('extension.list.module'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function parseFile(FileInterface $template_file) {
    return $this->parseStream('');
  }

  /**
   * {@inheritdoc}
   */
  public function parseStream($pdf_content) {
    return static::getParseResult();
  }

  /**
   * {@inheritdoc}
   */
  public function mergeFile(FileInterface $template_file, array $field_mappings, array $context) {
    return $this->mergeStream('', $field_mappings, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function mergeStream($pdf_content, array $field_mappings, array $context) {
    // Not really populated, but that isn't our job.
    $populated_pdf = file_get_contents($this->extensionListModule->getPath('fillpdf_test') . '/files/fillpdf_test_v3.pdf');

    $this->state->set('fillpdf_test.last_populated_metadata', [
      'field_mapping' => $field_mappings,
      'context' => $context,
    ]);

    return $populated_pdf;
  }

  /**
   * Returns a list of fields, as if a PDF file was parsed.
   *
   * Note that there is a duplicate field that get consolidated in
   * InputHelper::attachPdfToForm() at the latest.
   * The expected number of fields is therefore three, not four.
   *
   * @return array
   *   List of associative arrays representing fields.
   *
   * @see \Drupal\fillpdf\InputHelper::attachPdfToForm()
   */
  public static function getParseResult() {
    return [
      0 => [
        'name' => 'ImageField',
        'value' => '',
        'type' => 'Pushbutton',
      ],
      1 => [
        'name' => 'TestButton',
        'value' => '',
        'type' => 'Pushbutton',
      ],
      2 => [
        'name' => 'TextField1',
        'value' => '',
        'type' => 'Text',
      ],
      3 => [
        'name' => 'TextField2',
        'value' => '',
        'type' => 'Text',
      ],
      4 => [
        'name' => 'ImageField',
        'value' => '',
        'type' => 'Pushbutton',
      ],
    ];
  }

}
