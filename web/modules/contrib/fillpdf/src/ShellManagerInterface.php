<?php

namespace Drupal\fillpdf;

/**
 * Provides an interface for FillPDF execution manager.
 *
 * @internal
 */
interface ShellManagerInterface {

  /**
   * Replacement for percentage while escaping.
   */
  const PERCENTAGE_REPLACE = 'PERCENTSIGN';

  /**
   * Whether we are running on Windows OS.
   *
   * @return bool
   *   TRUE if we're running on Windows, otherwise FALSE.
   */
  public function isWindows();

  /**
   * Gets the list of locales installed on the server.
   *
   * @return string[]
   *   Associative array of installed locales as returned by 'locale -a' on *nix
   *   systems, keyed by itself. Will return an empty array on Windows servers.
   */
  public function getInstalledLocales();

  /**
   * Escapes a string.
   *
   * PHP escapeshellarg() drops non-ascii characters, this is a replacement.
   *
   * Stop-gap replacement until core issue #1561214 has been solved. Solution
   * proposed in #1502924-8.
   *
   * PHP escapeshellarg() on Windows also drops % (percentage sign) characters.
   * We prevent this by replacing it with a pattern that should be highly
   * unlikely to appear in the string itself and does not contain any
   * "dangerous" character at all (very wide definition of dangerous). After
   * escaping we replace that pattern back with a % character.
   *
   * @param string $arg
   *   The string to escape.
   *
   * @return string
   *   Escaped string.
   *
   * @see https://www.drupal.org/project/drupal/issues/1561214
   */
  public function escapeShellArg($arg);

}
