<?php

namespace Drupal\download_file\Controller;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download File controller.
 */
class DownloadFileController extends ControllerBase {

  /**
   * Page callback; Direct download of the given file.
   *
   * @param \Drupal\file\FileInterface $file
   *  The file entity.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *  The file download response.
   */
  public function downloadFileDirectDownload(FileInterface $file): BinaryFileResponse {
    $default_headers = file_get_content_headers($file);

    $custom_headers = [
      'Content-Type' => 'application/octet-stream',
      'Content-Disposition' => 'attachment; filename="' . $file->getFilename() . '"',
      'Content-Length' => $file->getSize(),
      'Content-Transfer-Encoding' => 'binary',
      'Pragma' => 'no-cache',
      'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
      'Expires' => '0',
      'Accept-Ranges' => 'bytes',
    ];
    $headers = array_merge($default_headers, $custom_headers);

    // Allow users to manage headers.
    $this->moduleHandler()->alter('download_file_headers', $headers, $file);

    return new BinaryFileResponse($file->getFileUri(), 200, $headers);
  }

  /**
   * Access callback for the download file controller.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   * @param \Drupal\file\FileInterface $file
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   */
  public function access(AccountInterface $account, FileInterface $file): AccessResultInterface {
    return $file->access('download', $account, TRUE);
  }

}
