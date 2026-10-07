<?php

namespace Drupal\fillpdf\Plugin\PdfBackend;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Utility\Error;
use Drupal\file\FileInterface;
use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\fillpdf\Plugin\PdfBackendBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Utils;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * LocalServer PdfBackend plugin.
 *
 * @PdfBackend(
 *   id = "local_server",
 *   label = @Translation("FillPDF LocalServer"),
 *   description = @Translation("Network-accessible, self-installed PDF API. You will need a VPS or dedicated server."),
 *   weight = 5
 * )
 */
class LocalServerPdfBackend extends PdfBackendBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a LocalServerPdfBackend plugin object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\Client $httpClient
   *   The Guzzle http client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger.factory service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected Client $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
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
      $container->get('http_client'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function parseFile(FileInterface $template_file) {
    $pdf_content = file_get_contents($template_file->getFileUri());
    return $this->parseStream($pdf_content);
  }

  /**
   * {@inheritdoc}
   */
  public function parseStream($pdf_content) {
    $request = [
      'pdf' => base64_encode($pdf_content),
    ];

    $json = Utils::jsonEncode($request);

    $fields_response = NULL;

    try {
      $fields_response = $this->httpClient->post($this->configuration['local_service_endpoint'] . '/api/v1/parse', [
        'body' => $json,
        'headers' => ['Content-Type' => 'application/json'],
      ]);
    }
    catch (RequestException $request_exception) {
      if ($response = $request_exception->getResponse()) {
        $this->messenger()->addError($this->t('Error %code. Reason: %reason.', [
          '%code' => $response->getStatusCode(),
          '%reason' => $response->getReasonPhrase(),
        ]));
      }
      else {
        $this->messenger()->addError($this->t('Unknown error occurred parsing PDF.'));
      }
    }

    if (!$fields_response) {
      return [];
    }

    $fields = Utils::jsonDecode((string) $fields_response->getBody(), TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function mergeFile(FileInterface $template_file, array $field_mappings, array $context) {
    $pdf_content = file_get_contents($template_file->getFileUri());
    return $this->mergeStream($pdf_content, $field_mappings, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function mergeStream($pdf_content, array $field_mappings, array $context) {
    $flatten = $context['flatten'];

    $api_fields = [];
    foreach ($field_mappings as $key => $mapping) {
      $api_field = NULL;

      if ($mapping instanceof TextFieldMapping) {
        $api_field = [
          'type' => 'text',
          'data' => $mapping->getData(),
        ];
      }
      elseif ($mapping instanceof ImageFieldMapping) {
        $api_field = [
          'type' => 'image',
          'data' => base64_encode($mapping->getData()),
        ];

        if ($extension = $mapping->getExtension()) {
          $api_field['extension'] = $extension;
        }
      }

      if ($api_field) {
        $api_fields[$key] = $api_field;
      }
    }

    $request = [
      'pdf' => base64_encode($pdf_content),
      'flatten' => $flatten,
      'fields' => $api_fields,
    ];

    $json = Utils::jsonEncode($request);

    try {
      $response = $this->httpClient->post($this->configuration['local_service_endpoint'] . '/api/v1/merge', [
        'body' => $json,
        'headers' => ['Content-Type' => 'application/json'],
      ]);

      $decoded = Utils::jsonDecode((string) $response->getBody(), TRUE);
      return base64_decode($decoded['pdf']);
    }
    catch (RequestException $e) {
      Error::logException($this->loggerFactory->get('fillpdf'), $e);
      return NULL;
    }
  }

}
