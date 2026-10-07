<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Core\Config\Config;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\fillpdf\Component\Utility\FillPdf;
use Drupal\fillpdf\Entity\FillPdfForm;

/**
 * Tests PDF parsing.
 *
 * @group fillpdf
 */
class PdfParseTest extends FillPdfTestBase {

  /**
   * Tests PDF population using local service.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testParseLocalService() {
    $this->configureLocalServiceBackend();

    $config = $this->config('fillpdf.settings');
    if (!FillPdf::checkLocalServiceEndpoint($this->container->get('http_client'), $config)) {
      $this->markTestSkipped('FillPDF LocalServer unavailable, so skipping test.');
    }

    $this->backendTest($config);
  }

  /**
   * Tests PDF population using a local install of pdftk.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testParsePdftk() {
    $this->configureFillPdf(['backend' => 'pdftk']);

    if (!FillPdf::checkPdftkPath()) {
      $this->markTestSkipped('pdftk not available, so skipping test.');
    }

    $this->backendTest($this->config('fillpdf.settings'));
  }

  /**
   * Tests a backend.
   *
   * @param \Drupal\Core\Config\Config $fillpdf_config
   *   FillPDF configuration object.
   *
   * @return \Drupal\fillpdf\FillPdfFormInterface
   *   The created FillPdfForm.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Core\Entity\EntityStorageException
   *
   * @internal
   *
   * @todo Consolidate duplicate code with PdfPopulationTest.
   * @todo This may be significantly simplified once we're initializing a
   *   FillPdfForm with the parsed values.
   * @see https://www.drupal.org/project/fillpdf/issues/3056400
   */
  protected function backendTest(Config $fillpdf_config) {
    $this->uploadTestPdf('fillpdf_Ŧäßð_v3â.pdf');
    $this->assertSession()->pageTextNotContains('No fields detected in PDF.');

    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());
    $fields = $fillpdf_form->getFormFields();
    $this->assertCount($this->getExpectedFieldCount($fillpdf_config->get('backend')), $fields);

    // Get the uploaded template's file ID.
    $previous_file_id = $fillpdf_form->file->target_id;

    // Set public scheme so populated files are saved to disk.
    $fillpdf_form->scheme = 'public';
    $fillpdf_form->save();

    // Populate a non-flattened sample PDF file and do some checks.
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $fillpdf_form->id(),
        'sample' => 1,
        'flatten' => 0,
      ],
    ]);
    $this->drupalGet($fillpdf_route);
    $this->assertSession()->pageTextNotContains('Merging the FillPDF Form failed');

    // Retrieve the last file ID which should be the sample file.
    // @todo When using pdftk, the saved xfdf file leads to the file counter
    // being increased by two instead of 1. We're therefore only comparing by
    // "greater than".
    $file_id = $this->getLastFileId();
    static::assertTrue($file_id > $previous_file_id, 'Populated PDF was saved as a new managed file.');

    // Load the sample file and check it is a PDF.
    $file = File::load($file_id);
    static::assertEquals('application/pdf', $file->getMimeType());

    // Create an instance of the backend plugin.
    $backend_manager = $this->container->get('plugin.manager.fillpdf.pdf_backend');
    $backend = $backend_manager->createInstance($fillpdf_config->get('backend'), $fillpdf_config->get());

    // Re-parse the sample PDF file and check for each text field that the
    // field value equals the field name (now in angle brackets, since the
    // sample functionality does that).
    foreach ($backend->parseFile($file) as $field) {
      if ($field['type'] == 'Text') {
        $value = $field['value'] ?? NULL;
        static::assertEquals("<{$field['name']}>", $value);
      }
    }

    return $fillpdf_form;
  }

  /**
   * Get expected field count per backend.
   *
   * Different backends process different types of fields. This method is used
   * by ::backendTest() to assert against the correct value.
   *
   * @param string $backend
   *   The backend.
   *
   * @return int
   *   The expected field count.
   */
  protected function getExpectedFieldCount($backend) {
    // NOTE: Other bugs led me to believe this was the case, but it's kind of a
    // useful method, so I'm just leaving it for now.
    switch ($backend) {
      case 'local_server':
      case 'pdftk':
        return 12;
    }

    throw new \LogicException("Unexpected call to PdfParseTest::getExpectedFieldCount() with \$backend = $backend");
  }

}
