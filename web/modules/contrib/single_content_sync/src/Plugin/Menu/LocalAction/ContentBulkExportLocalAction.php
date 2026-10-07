<?php

namespace Drupal\single_content_sync\Plugin\Menu\LocalAction;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Menu\LocalActionDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds bulk export local action options from the current route.
 */
class ContentBulkExportLocalAction extends LocalActionDefault {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

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
   * Constructs a ContentBulkExportLocalAction object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider to load routes by name.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteProviderInterface $route_provider,
    RouteMatchInterface $route_match,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $route_provider);

    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('router.route_provider'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(?Request $request = NULL) {
    $entity_type_id = $this->pluginDefinition['options']['query']['entity_type'] ?? NULL;
    if (!$entity_type_id || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return parent::getTitle($request);
    }

    $bundle = $this->getBundle($this->routeMatch);
    if ($bundle) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
      $label = $bundles[$bundle]['label'] ?? $bundle;
      return new TranslatableMarkup('Export all @label', [
        '@label' => mb_strtolower((string) $label),
      ]);
    }

    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $label = $entity_type->get('label_plural') ?: $entity_type->getLabel();
    return new TranslatableMarkup('Export all @label', [
      '@label' => mb_strtolower((string) $label),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions(RouteMatchInterface $route_match) {
    $options = parent::getOptions($route_match);
    $bundle = $this->getBundle($route_match);

    if ($bundle) {
      $options['query']['bundle'] = $bundle;
    }

    return $options;
  }

  /**
   * Gets the bundle from the plugin definition or the current route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return string|null
   *   The bundle ID, or NULL if no bundle applies.
   */
  protected function getBundle(RouteMatchInterface $route_match): ?string {
    if (!empty($this->pluginDefinition['options']['query']['bundle'])) {
      return $this->pluginDefinition['options']['query']['bundle'];
    }

    $bundle_route_parameter = $this->pluginDefinition['bundle_route_parameter'] ?? NULL;
    if (!$bundle_route_parameter || !$route_match->getParameter($bundle_route_parameter)) {
      return NULL;
    }

    $bundle = $route_match->getParameter($bundle_route_parameter);
    return $bundle instanceof EntityInterface
      ? $bundle->id()
      : (string) $bundle;
  }

}
