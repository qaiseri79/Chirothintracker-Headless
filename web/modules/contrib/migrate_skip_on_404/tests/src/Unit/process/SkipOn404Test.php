<?php

namespace Drupal\Tests\migrate_skip_on_404\Unit\process;

use Drupal\migrate\MigrateSkipProcessException;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate_skip_on_404\Plugin\migrate\process\SkipOn404;
use Drupal\Tests\migrate\Unit\process\MigrateProcessTestCase;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests the skip on 404 process plugin.
 *
 * @group migrate
 * @coversDefaultClass \Drupal\migrate_skip_on_404\Plugin\migrate\process\Skipon404
 */
class SkipOn404Test extends MigrateProcessTestCase {

  /**
   * @covers ::process
   * @covers ::checkFile
   */
  public function testProcessMissingLocalFile(): void {
    $configuration['method'] = 'process';
    $mockHttp = new HttpClient([]);

    $value = dirname(__FILE__) . '/not-existing.txt';
    $this->expectException(MigrateSkipProcessException::class);
    (new SkipOn404($configuration, 'skip_on_404', [], $mockHttp))
      ->transform($value, $this->migrateExecutable, $this->row, 'destinationproperty');
  }

  /**
   * @covers ::process
   * @covers ::checkFile
   */
  public function testProcessExistingFiles(): void {
    $configuration['method'] = 'process';

    $mock = new MockHandler([
      new Response(200, [], ''),
    ]);
    $handler = HandlerStack::create($mock);
    $mockHttp = new HttpClient(['handler' => $handler]);

    $process = new SkipOn404($configuration, 'skip_on_404', [], $mockHttp);
    foreach ([__FILE__, "https://example.com/"] as $value) {
      $result = $process->transform($value, $this->migrateExecutable, $this->row, 'destinationproperty');
      $this->assertEquals($value, $result);
    }
  }

  /**
   * @covers ::row
   * @covers ::checkFile
   */
  public function testRowMissingLocalFile(): void {
    $configuration['method'] = 'row';

    $value = dirname(__FILE__) . '/not-existing.txt';
    $mockHttp = new HttpClient([]);

    $this->expectException(MigrateSkipRowException::class);
    $this->expectExceptionMessage('404 - ' . $value . ' does not exist');
    (new SkipOn404($configuration, 'skip_on_404', [], $mockHttp))
      ->transform($value, $this->migrateExecutable, $this->row, 'destinationproperty');
  }

  /**
   * @covers ::row
   * @covers ::checkFile
   */
  public function testRowMissingRemoteFile(): void {
    $configuration['method'] = 'row';

    $mock = new MockHandler([
      new Response(400, [], ''),
    ]);
    $handler = HandlerStack::create($mock);
    $mockHttp = new HttpClient(['handler' => $handler]);

    $value = "https://example.com/";
    $this->expectException(MigrateSkipRowException::class);
    $this->expectExceptionMessage('404 - ' . $value . ' does not exist');
    (new SkipOn404($configuration, 'skip_on_404', [], $mockHttp))
      ->transform($value, $this->migrateExecutable, $this->row, 'destinationproperty');
  }

}
