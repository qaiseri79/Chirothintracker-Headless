<?php

namespace Drupal\fillpdf;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Manage execution of shell commands.
 *
 * @internal
 */
class ShellManager implements ShellManagerInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Whether we are running on Windows OS.
   *
   * @var bool
   */
  protected $isWindows;

  /**
   * Constructs a ShellManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
    $this->isWindows = substr(PHP_OS, 0, 3) === 'WIN';
  }

  /**
   * {@inheritdoc}
   */
  public function isWindows() {
    return $this->isWindows;
  }

  /**
   * {@inheritdoc}
   */
  public function getInstalledLocales() {
    if ($this->isWindows()) {
      return [];
    }

    $output = [];
    $status = NULL;
    exec("locale -a", $output, $status);
    return array_combine($output, $output);
  }

  /**
   * {@inheritdoc}
   */
  public function escapeShellArg($arg) {
    // Put the configured locale in a static to avoid multiple config get calls
    // in the same request.
    static $config_locale;

    if (!isset($config_locale)) {
      $config_locale = $this->configFactory->get('fillpdf.settings')->get('shell_locale');
    }

    $current_locale = setlocale(LC_CTYPE, 0);

    if ($this->isWindows()) {
      // Temporarily replace % characters.
      $arg = str_replace('%', static::PERCENTAGE_REPLACE, $arg);
    }

    if ($current_locale !== $config_locale) {
      // Temporarily swap the current locale with the configured one, if
      // available. Otherwise fall back.
      setlocale(LC_CTYPE, [$config_locale, 'C.UTF-8', $current_locale]);
    }

    $arg_escaped = escapeshellarg($arg);

    if ($current_locale !== $config_locale) {
      // Restore the current locale.
      setlocale(LC_CTYPE, $current_locale);
    }

    // Get our % characters back.
    if ($this->isWindows()) {
      $arg_escaped = str_replace(static::PERCENTAGE_REPLACE, '%', $arg_escaped);
    }

    return $arg_escaped;
  }

}
