<?php

namespace Drupal\commerce_product_restriction\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\CategorizingPluginManagerTrait;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * A plugin manager.
 *
 * Https://www.drupal.org/docs/drupal-apis/plugin-api/creating-your-own-plugin-manager
 * https://medium.com/@uditrawat/custom-plugin-type-in-drupal-8-5c243b4ed152
 *
 * @see \Drupal\commerce_product_restriction\Annotation\ProductRestrictionPlugin
 * @see \Drupal\commerce_product_restriction\ProductRestrictionPluginInterface
 * @see plugin_api
 */
class ProductRestrictionPluginManager extends DefaultPluginManager {
  use CategorizingPluginManagerTrait;

  /**
   * Constructs a ArchiverManager object.
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
    parent::__construct(
      'Plugin/Commerce/ProductRestriction',
      $namespaces,
      $module_handler,
      'Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginInterface',
      'Drupal\commerce_product_restriction\Annotation\ProductRestrictionPlugin'
    );
    $this->alterInfo('commerce_product_restriction_product_restriction_info');
    $this->setCacheBackend($cache_backend, 'commerce_product_restriction_product_restriction_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function getFilteredDefinitions($parent_entity_type_id, array $entity_type_ids) {
    $definitions = $this->getDefinitions();
    foreach ($definitions as $plugin_id => $definition) {
      // Filter by entity type.
      if (isset($definition['entity_type']) && !in_array($definition['entity_type'], $entity_type_ids)) {
        unset($definitions[$plugin_id]);
        continue;
      }
      // Filter by parent_entity_type, if specified by the plugin.
      if (!empty($definition['parent_entity_type'])) {
        if ($definition['parent_entity_type'] != $parent_entity_type_id) {
          unset($definitions[$plugin_id]);
        }
      }
    }

    // @todo allow modules to filter the condition list:
    /*
    $event = new FilterConditionsEvent($definitions, $parent_entity_type_id);
    $this->eventDispatcher->dispatch(CommerceEvents::FILTER_CONDITIONS, $event);
    $definitions = $event->getDefinitions();
    // Sort by weigh and display label.
    uasort($definitions, function ($a, $b) {
    if ($a['weight'] == $b['weight']) {
    return strnatcasecmp($a['display_label'], $b['display_label']);
    }
    return ($a['weight'] < $b['weight']) ? -1 : 1;
    });
     */

    return $definitions;
  }

}
