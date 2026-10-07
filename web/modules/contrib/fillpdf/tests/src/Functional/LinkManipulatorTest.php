<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\fillpdf\Traits\TestFillPdfTrait;

/**
 * @coversDefaultClass \Drupal\fillpdf\Service\FillPdfLinkManipulator
 *
 * @group fillpdf
 *
 * @todo Convert into a unit test.
 */
class LinkManipulatorTest extends BrowserTestBase {

  use TestFillPdfTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['fillpdf_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The FillPDF link manipulator service.
   *
   * @var \Drupal\fillpdf\Service\FillPdfLinkManipulator
   */
  protected $linkManipulator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configureFillPdf();
    $this->initializeUser();
    $this->linkManipulator = $this->container->get('fillpdf.link_manipulator');
  }

  /**
   * Tests handling of a non-existing FillPdfForm ID.
   */
  public function testLinkExceptions() {
    // Hit the generation route with no query string set.
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], []);
    $this->drupalGet($fillpdf_route);

    // Hit the generation route with no fid set.
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'sample' => 1,
      ],
    ]);
    $this->drupalGet($fillpdf_route);
    // Ensure the exception is converted to an error and access is denied.
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextContains("No FillPDF Form was specified in the query string, so failing.");

    // Hit the generation route with a non-existing fid set.
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => 1234,
      ],
    ]);
    $this->drupalGet($fillpdf_route);
    // Ensure the exception is converted to an error and access is denied.
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextContains("The requested FillPDF Form doesn't exist, so failing.");
  }

  /**
   * Tests parsing a sample link.
   */
  public function testSampleLink() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();

    // Prepare a query with the sample flag and all kinds of (redundant)
    // entity parameters set.
    $query = [
      'fid' => $form_id,
      'entity_type' => 'user',
      'entity_id' => 3,
      'entity_ids' => ['node:1', 'node:2'],
      'sample' => TRUE,
    ];
    $url = Url::fromRoute('fillpdf.populate_pdf', [], ['query' => $query]);
    $context = $this->linkManipulator->parseLink($url);

    // Test 'fid' and 'sample' parameters are correctly set.
    $this->assertEquals($form_id, $context['fid']);
    $this->assertEquals(TRUE, $context['sample']);

    // Make sure 'entity_ids' is empty and all other entity parameters stripped.
    $this->assertEmpty($context['entity_ids']);
    $this->assertArrayNotHasKey('entity_type', $context);
    $this->assertArrayNotHasKey('entity_id', $context);
  }

}
