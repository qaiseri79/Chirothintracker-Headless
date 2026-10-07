<?php

namespace Drupal\fillpdf\Plugin;

use Drupal\Component\Plugin\FallbackPluginManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\fillpdf\Annotation\PdfBackend;

/**
 * Provides the FillPDF PdfBackend plugin manager.
 */
class PdfBackendManager extends DefaultPluginManager implements FallbackPluginManagerInterface {

  /**
   * Constructs a new PdfBackendManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/PdfBackend', $namespaces, $module_handler, PdfBackendInterface::class, PdfBackend::class);

    $this->alterInfo('fillpdf_pdfbackend_info');
    $this->setCacheBackend($cache_backend, 'fillpdf_pdfbackend_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackPluginId($plugin_id, array $configuration = []) {
    return $plugin_id === 'local_service' ? 'local_server' : $plugin_id;
  }

  /**
   * Gets the definitions of all FillPDF backend plugins.
   *
   * @return mixed[]
   *   An associative array of plugin definitions, keyed by plugin ID and sorted
   *   by weight.
   */
  public function getDefinitions() {
    // Get all plugin definitions of this type.
    $definitions = parent::getDefinitions();

    // Sort plugins by weight.
    uasort($definitions, function ($a, $b) {
      return $a['weight'] - $b['weight'];
    });

    return $definitions;
  }

}
