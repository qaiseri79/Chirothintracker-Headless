<?php

/**
 * @file
 * Hooks for the download_file module.
 */

use Drupal\file\FileInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the array of headers.
 *
 * @param array $headers
 *   Array of headers.
 * @param \Drupal\file\FileInterface $file
 *   File to download.
 */
function hook_download_file_headers_alter(array &$headers, FileInterface $file): void {
  $headers['Expires'] = 0;
}

/**
 * @} End of "addtogroup hooks".
 */
