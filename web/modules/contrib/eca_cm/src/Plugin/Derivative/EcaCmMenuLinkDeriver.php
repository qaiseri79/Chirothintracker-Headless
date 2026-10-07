<?php

namespace Drupal\eca_cm\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca_cm\EcaInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver for dynamically generated menu links.
 */
final class EcaCmMenuLinkDeriver extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    private EcaInfo $ecaInfo,
    private ModuleHandlerInterface $moduleHandler
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get(EcaInfo::class),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    if (!isset($this->derivatives['list']) && $this->ecaInfo->usesModelerApi() && !$this->moduleHandler->moduleExists('eca_ui')) {
      $this->derivatives['list'] = [
        'route_name' => 'eca_cm.list',
        'title' => new TranslatableMarkup('ECA'),
        'description' => new TranslatableMarkup('Configure ECA - Events, Conditions, Actions.'),
        'parent' => 'system.admin_config_workflow',
        'weight' => 10,
      ] + $base_plugin_definition;
    }
    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
