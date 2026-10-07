<?php

namespace Drupal\eca_cm\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca_cm\EcaInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver for dynamically generated local actions.
 */
final class EcaCmLocalActionDeriver extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    private EcaInfo $ecaInfo
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get(EcaInfo::class)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    if (!isset($this->derivatives['add'])&& $this->ecaInfo->usesModelerApi()) {
      $this->derivatives['add'] = [
        'route_name' => 'eca_cm.add',
        'appears_on' => ['entity.eca.collection', 'eca_cm.list'],
        'title' => new TranslatableMarkup('Add Classic model'),
        'weight' => 10,
      ] + $base_plugin_definition;
    }
    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
