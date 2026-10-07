<?php

namespace Drupal\migrate_skip_on_404\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\UrlHelper;

/**
 * Skips processing the current row when the input file url is not exist.
 *
 * Available configuration keys:
 * - method: (optional) What to do if the input file uri does not exist.
 *   - row: Skips the entire row.
 *   - process: Prevents further processing of the input property.
 *
 * Examples:
 *
 * @code
 * process:
 *   file:
 *     plugin: skip_on_404
 *     method: row
 *     source: fileurl
 * @endcode
 * The above example will skip processing any row
 * if file 'fileurl' does not exist
 * and log the message in the message table.
 *
 * @MigrateProcessPlugin(
 *   id = "skip_on_404"
 * )
 */
class SkipOn404 extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The Guzzle HTTP Client service.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Client $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function row($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!$this->checkFile($value)) {
      throw new MigrateSkipRowException(sprintf('404 - %s does not exist', $value));
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function process($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!$this->checkFile($value)) {
      $this->stopPipeline();
      return NULL;
    }
    return $value;
  }

  /**
   * Check if file (remote or local) exists.
   *
   * @param mixed $value
   *   File URL.
   *
   * @return bool
   *   True if the compare successfully, FALSE otherwise.
   */
  protected function checkFile($value) {
    if (UrlHelper::isExternal($value)) {
      try {
        // Check if remote file exists.
        $this->httpClient->head($value);
      }
      catch (RequestException $e) {
        return FALSE;
      }
    }
    // Check if local file exists.
    elseif (!file_exists($value)) {
      return FALSE;
    }
    return TRUE;
  }

}
