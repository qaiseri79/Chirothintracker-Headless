<?php

namespace Drupal\eca_cm;

use Drupal\Core\Extension\ExtensionPathResolver;
use Symfony\Component\Yaml\Yaml;

/**
 * Helper to determine certain information regarding the installed ECA version.
 */
final class EcaInfo {

  /**
   * Get the service instance of this class.
   *
   * @return self
   */
  public static function service(): self {
    return \Drupal::service(self::class);
  }

  public function __construct(
    private ExtensionPathResolver $extensionPathResolver
  ) {}

  /**
   * @return bool
   */
  public function usesModelerApi(): bool {
    static $return = NULL;

    if (NULL === $return) {
      $return = FALSE;

      // Find the ECA module base path even if the module isn’t enabled.
      $ecaPath = $this->extensionPathResolver->getPath('module', 'eca');

      $infoPath = $ecaPath ? \Drupal::root() . '/' . $ecaPath . '/modules/ui/eca_ui.info.yml' : NULL;

      try {
        $info = is_readable($infoPath) ? (Yaml::parseFile($infoPath) ?? []) : [];
      }
      catch (\Throwable $e) {
        $info = [];
      }

      $deps = $info['dependencies'] ?? [];

      foreach (is_array($deps) ? $deps : [] as $dep) {
        if (!is_string($dep)) {
          continue;
        }
        if (str_ends_with($dep, 'modeler_api')) {
          $return = TRUE;
        }
      }
    }

    return $return;
  }

}
