<?php

namespace Drupal\Tests\fillpdf\Traits;

/**
 * Provides methods for testing FillPdf.
 *
 * This trait is meant to be used only by test classes.
 */
trait TestFillPdfTrait {

  /**
   * Configures schemes and backend.
   *
   * @param array $configuration
   *   (optional) Associative array containing configuration to be set. This may
   *   contain the following keys:
   *   - 'allowed_schemes': string[] (default: ['public', 'private']
   *   - 'template_scheme': string (default: the site default)
   *   - 'backend': string (default: 'test')
   */
  protected function configureFillPdf(array $configuration = []) {
    // Merge in defaults.
    $configuration += [
      'allowed_schemes' => ['public', 'private'],
      'template_scheme' => $this->config('system.file')->get('default_scheme'),
      'backend' => 'test',
    ];

    /** @var \Drupal\Core\Config\ConfigFactoryInterface $config_factory */
    $config_factory = $this->container->get('config.factory');

    // Set FillPDF backend and scheme.
    $config = $config_factory->getEditable('fillpdf.settings')
      ->set('allowed_schemes', $configuration['allowed_schemes'])
      ->set('template_scheme', $configuration['template_scheme'])
      ->set('backend', $configuration['backend']);

    // PDFtk needs to have the correct locale set in the environment or the
    // test will fail.
    if ($configuration['backend'] === 'pdftk') {
      /** @var \Drupal\fillpdf\ShellManagerInterface $shell_manager */
      $shell_manager = $this->container->get('fillpdf.shell_manager');
      $locales = $shell_manager->getInstalledLocales();
      $config->set('shell_locale', isset($locales['en_US.UTF-8']) ? 'en_US.UTF-8' : 'en_US.utf8');
    }

    $config->save();
  }

  /**
   * Configures the FillPdf local service backend.
   */
  protected function configureLocalServiceBackend() {
    // Configure our local filling service. You need to set up your development
    // environment to run the Docker container at http://127.0.0.1:8085 if you
    // are developing for FillPDF and want to run this test. This can be
    // overridden with the FILLPDF_LOCAL_SERVER environment variable in
    // phpunit.xml.
    $localServerEndpoint = getenv('FILLPDF_LOCAL_SERVER');
    $edit = [
      'template_scheme' => 'public',
      'backend' => 'local_server',
      'local_service_endpoint' => $localServerEndpoint ?: 'http://127.0.0.1:8085',
    ];
    $this->drupalGet('admin/config/media/fillpdf');
    $this->submitForm($edit, (string) t('Save configuration'));
  }

  /**
   * Configures the FillPdf service backend.
   *
   * @param string $api_key
   *   An API key.
   * @param string $api_endpoint
   *   An API endpoint.
   */
  protected function configureFillPdfServiceBackend($api_key, $api_endpoint) {
    // Configure FillPDF Service.
    $edit = [
      'template_scheme' => 'public',
      'backend' => 'fillpdf_service_v2',
      'fillpdf_service_api_key' => $api_key,
      'fillpdf_service_api_endpoint' => $api_endpoint,
    ];
    $this->drupalGet('admin/config/media/fillpdf');
    $this->submitForm($edit, t('Save configuration'));
  }

  /**
   * Creates a privileged user account and logs in.
   */
  protected function initializeUser() {
    // Create and log in our privileged user.
    $account = $this->drupalCreateUser([
      'access administration pages',
      'administer pdfs',
    ]);
    $this->drupalLogin($account);
  }

  /**
   * Uploads a specified PDF test file, if given.
   *
   * @param string|null $filename
   *   (optional) Filename of the PDF test file. Defaults to NULL.
   */
  protected function uploadTestPdf($filename = NULL) {
    if ($filename) {
      $path = $this->getTestPdfPath($filename);
      $this->assertNotFalse($path);
    }

    $edit = [
      'files[upload_pdf]' => $path ?? NULL,
    ];
    $this->drupalGet('admin/structure/fillpdf');
    $this->submitForm($edit, 'Create');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Gets the ID of the latest fillpdf_form stored.
   *
   * @return int
   *   ID of the lates FillPdf Form stored.
   */
  protected function getLatestFillPdfForm() {
    $entity_query = $this->container->get('entity_type.manager')
      ->getStorage('fillpdf_form')
      ->getQuery()
      ->accessCheck(TRUE);
    $max_fid_after_result = $entity_query
      ->sort('fid', 'DESC')
      ->range(0, 1)
      ->execute();
    return reset($max_fid_after_result);
  }

  /**
   * Gets the absolute local filepath of a PDF test file.
   *
   * @param string $filename
   *   Filename of the PDF test file.
   *
   * @return string|false
   *   The absolute locale filepath or FALSE on failure.
   */
  protected function getTestPdfPath($filename) {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    return $file_system->realpath(\Drupal::service('extension.list.module')->getPath('fillpdf') . '/tests/modules/fillpdf_test/files/' . $filename);
  }

  /**
   * Gets the MIME type.
   *
   * @param string $content
   *   Content to check for the MIME type.
   *
   * @return string
   *   The MIME type.
   *
   * @todo Consider using FileManagedTestBase::createFile() instead.
   * @see \Drupal\Tests\file\Functional\FileManagedTestBase::createFile()
   */
  protected function getMimeType($content) {
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    return $finfo->buffer($content);
  }

}
