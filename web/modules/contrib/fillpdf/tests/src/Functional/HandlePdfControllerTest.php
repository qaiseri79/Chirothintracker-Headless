<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Test HandlePdfController.
 *
 * Covers \Drupal\fillpdf\Controller\HandlePdfController,
 * \Drupal\fillpdf\Plugin\FillPdfActionPlugin, and
 * \Drupal\fillpdf\OutputHandler.
 *
 * @group fillpdf
 *
 * @todo Convert into a unit test.
 */
class HandlePdfControllerTest extends FillPdfUploadTestBase {

  /**
   * Array of nodes to run tests with.
   *
   * @var array
   */
  private $testNodes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->testNodes[1] = $this->createNode([
      'title' => 'Hello',
      'type' => 'article',
    ]);
    $this->testNodes[2] = $this->createNode([
      'title' => 'Goodbye',
      'type' => 'article',
    ]);
  }

  /**
   * Tests DownloadAction.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testDownloadAction() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();

    $fid_before = $this->getLastFileId();
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $form_id,
        'sample' => 1,
      ],
    ]);
    $this->drupalGet($fillpdf_route);
    $fid_after = $this->getLastFileId();

    // Make sure the PDF file has not been saved.
    $this->assertEquals($fid_before, $fid_after);

    // Make sure we are seeing the downloaded PDF.
    $this->assertSession()->statusCodeEquals(200);
    $maybe_pdf = $this->getSession()->getPage()->getContent();
    static::assertEquals('application/pdf', $this->getMimeType($maybe_pdf), 'The file has the correct MIME type.');

    // Ensure the headers are set to make the PDF download.
    $this->assertSession()->responseHeaderContains('Content-Disposition', ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/pdf');
    $this->assertSession()->responseHeaderContains('Content-Length', (string) strlen(file_get_contents($this->getTestPdfPath('fillpdf_test_v3.pdf'))));
  }

  /**
   * Tests SaveAction.
   */
  public function testSaveAction() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();
    $edit = [
      'scheme' => 'public',
    ];
    $this->drupalGet("admin/structure/fillpdf/{$form_id}");
    $this->submitForm($edit, 'Save');

    $fid_before = $this->getLastFileId();
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $form_id,
        'sample' => 1,
      ],
    ]);
    $this->drupalGet($fillpdf_route);
    $fid_after = $this->getLastFileId();

    // Make sure the PDF file has been saved.
    $this->assertEquals($fid_before + 1, $fid_after);

    // Make sure we are /not/ redirected to the PDF.
    $this->assertSession()->statusCodeEquals(200);
    $maybe_pdf = $this->getSession()->getPage()->getContent();
    static::assertNotEquals('application/pdf', $this->getMimeType($maybe_pdf), "The file has the correct MIME type.");
  }

  /**
   * Tests RedirectAction.
   */
  public function testRedirectAction() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();
    $edit = [
      'scheme' => 'public',
      'destination_redirect[value]' => TRUE,
    ];
    $this->drupalGet("admin/structure/fillpdf/{$form_id}");
    $this->submitForm($edit, 'Save');

    $fid_before = $this->getLastFileId();
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $form_id,
        'sample' => 1,
      ],
    ]);
    $this->drupalGet($fillpdf_route);
    $fid_after = $this->getLastFileId();

    // Make sure the PDF file has been saved.
    $this->assertEquals($fid_before + 1, $fid_after);

    // Make sure we have been redirected to the PDF.
    $this->assertSession()->statusCodeEquals(200);
    $maybe_pdf = $this->getSession()->getPage()->getContent();
    static::assertEquals('application/pdf', $this->getMimeType($maybe_pdf), "The file has the correct MIME type.");
  }

  /**
   * Tests filename and destination of a populated PDF file.
   */
  public function testTokenFilenameDestination() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();
    $edit = [
      'title[0][value]' => '[current-date:html_year]-[user:account-name]-[node:title].pdf',
      'scheme' => 'public',
      'destination_path[0][value]' => '[current-date:html_year]-[user:account-name]-[node:title]',
    ];
    $this->drupalGet("admin/structure/fillpdf/{$form_id}");
    $this->submitForm($edit, 'Save');

    $year = date('Y');
    $node1_id = $this->testNodes[1]->id();
    $node1_title = $this->testNodes[1]->getTitle();
    $node2_id = $this->testNodes[2]->id();
    $node2_title = $this->testNodes[2]->getTitle();
    $user_id = $this->adminUser->id();
    $user_name = $this->adminUser->getAccountName();

    $test_cases = [];
    // Test case 0: no entity.
    $test_cases[1]['entities'] = [];
    $test_cases[1]['expected'] = "{$year}--";

    // Test case 1: existing node.
    $test_cases[1]['entities'] = ["node:{$node1_id}"];
    $test_cases[1]['expected'] = "{$year}--{$node1_title}";

    // Test case 2: two existing nodes.
    $test_cases[2]['entities'] = ["node:{$node1_id}", "node:{$node2_id}"];
    $test_cases[2]['expected'] = "{$year}--{$node2_title}";

    // Test case 3: twice the same node.
    $test_cases[3]['entities'] = ["node:{$node1_id}", "node:{$node1_id}"];
    $test_cases[3]['expected'] = "{$year}--{$node1_title}";

    // Test case 4: existing user.
    $test_cases[4]['entities'] = ["user:{$user_id}"];
    $test_cases[4]['expected'] = "{$year}-{$user_name}-";

    // Test case 5: existing node and existing user.
    $test_cases[5]['entities'] = ["node:{$node1_id}", "user:{$user_id}"];
    $test_cases[5]['expected'] = "{$year}-{$user_name}-{$node1_title}";

    // Test case 6: non-existing node.
    $test_cases[6]['entities'] = ["node:123"];
    $test_cases[6]['expected'] = "{$year}--";

    // Test case 7: existing node and non-existing user.
    $test_cases[7]['entities'] = ["node:{$node1_id}", "user:456"];
    $test_cases[7]['expected'] = "{$year}--{$node1_title}";

    foreach ($test_cases as $id => $case) {
      // Hit the generation route.
      $entities = $case['entities'];
      $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
        'query' => [
          'fid' => $form_id,
          'entity_ids' => $entities,
        ],
      ]);
      $this->drupalGet($fillpdf_route);

      // Get last file and check if filename and path are correct.
      $file = File::load($this->getLastFileId());
      $filename = $file->getFilename();
      $uri = $file->getFileUri();

      $expected = $case['expected'];
      $this->assertEquals("{$expected}.pdf", $filename, "Test case $id: The file has the filename $filename.");
      $this->assertEquals("public://fillpdf/{$expected}/{$expected}.pdf", $uri, "Test case $id: The file has the expected URI.");

      // Check if file is permanent and has the right format.
      // @todo Remove $message when resolved:
      // @see https://www.drupal.org/project/drupal/issues/3043127
      $message = (string) new FormattableMarkup('File %file is permanent.', [
        '%file' => $file->getFileUri(),
      ]);
      $this->assertFileIsPermanent($file, $message);
      $this->drupalGet(\Drupal::service('file_url_generator')->generateAbsoluteString($uri));
      $maybe_pdf = $this->getSession()->getPage()->getContent();
      static::assertEquals('application/pdf', $this->getMimeType($maybe_pdf), "Test case $id: The file has the correct MIME type.");

      // Delete the file, so we don't run into conflicts with the next testcase.
      $file->delete();
    }
  }

  /**
   * Tests handling of an no longer allowed storage scheme.
   */
  public function testStorageSchemeDisallowed() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();
    $previous_file_id = $this->getLastFileId();
    $edit = [
      'admin_title[0][value]' => 'Scheme test',
      'scheme' => 'public',
      'destination_path[0][value]' => 'test',
    ];
    $this->submitForm($edit, 'Save');

    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $form_id,
      ],
    ]);

    // Hit the generation route. Make sure we are redirected to the front page.
    $this->drupalGet($fillpdf_route);
    $this->assertSession()->addressNotEquals('/fillpdf');
    $this->assertSession()->statusCodeEquals(200);
    // Get back to the front page and make sure the file was stored in the
    // private storage.
    $this->drupalGet('<front>');
    $this->assertSession()->pageTextNotContains('File storage scheme public:// is unavailable');
    $this->assertEquals(++$previous_file_id, $this->getLastFileId(), 'Generated file was stored.');
    $this->assertStringStartsWith('public://', File::load($this->getLastFileId())->getFileUri());

    // Now disallow the public scheme.
    $this->configureFillPdf(['allowed_schemes' => ['private']]);

    // Hit the generation route again. This time we should be redirected to the
    // PDF file. Make sure no PHP error occurred.
    $this->drupalGet($fillpdf_route);
    $this->assertSession()->addressEquals('/fillpdf');
    $this->assertSession()->statusCodeEquals(200);
    // Get back to the front page and check if an error was set, and we didn't
    // try to store the file.
    $this->drupalGet('<front>');
    $this->assertSession()->pageTextContains("File storage scheme public:// is unavailable, so a PDF file generated from FillPDF form Scheme test ($form_id) could not be stored.");
    $this->assertEquals($previous_file_id, $this->getLastFileId(), 'Generated file was not stored.');
  }

  /**
   * Tests handling of an unavailable storage scheme.
   */
  public function testStorageSchemeUnavailable() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();
    $previous_file_id = $this->getLastFileId();
    $edit = [
      'admin_title[0][value]' => 'Scheme test',
      'scheme' => 'private',
      'destination_path[0][value]' => 'test',
    ];
    $this->submitForm($edit, 'Save');

    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $form_id,
      ],
    ]);

    // Hit the generation route. Make sure we are redirected to the front page.
    $this->drupalGet($fillpdf_route);
    $this->assertSession()->addressNotEquals('/fillpdf');
    $this->assertSession()->statusCodeEquals(200);
    // Get back to the front page and make sure the file was stored in the
    // private storage.
    $this->drupalGet('<front>');
    $this->assertSession()->pageTextNotContains('File storage scheme private:// is unavailable');
    $this->assertEquals(++$previous_file_id, $this->getLastFileId(), 'Generated file was stored.');
    $this->assertStringStartsWith('private://', File::load($this->getLastFileId())->getFileUri());

    // Now remove the private path from settings.php and rebuild the container.
    $this->writeSettings([
      'settings' => [
        'file_private_path' => (object) [
          'value' => '',
          'required' => TRUE,
        ],
      ],
    ]);
    $this->rebuildContainer();

    // Hit the generation route again. This time we should be redirected to the
    // PDF file. Make sure no PHP error occurred.
    $this->drupalGet($fillpdf_route);
    $this->assertSession()->addressEquals('/fillpdf');
    $this->assertSession()->statusCodeEquals(200);
    // Get back to the front page and check if an error was set, and we didn't
    // try to store the file.
    $this->drupalGet('<front>');
    $this->assertSession()->pageTextContains("File storage scheme private:// is unavailable, so a PDF file generated from FillPDF form Scheme test ($form_id) could not be stored.");
    $this->assertEquals($previous_file_id, $this->getLastFileId(), 'Generated file was not stored.');
  }

}
