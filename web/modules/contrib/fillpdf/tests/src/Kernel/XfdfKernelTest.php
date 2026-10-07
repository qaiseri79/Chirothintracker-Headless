<?php

namespace Drupal\Tests\fillpdf\Kernel;

use Drupal\fillpdf\Component\Utility\Xfdf;
use Drupal\KernelTests\KernelTestBase;

/**
 * Test the XfdfUnitTest class.
 *
 * @group fillpdf
 * @covers \Drupal\fillpdf\Component\Utility\Xfdf
 */
class XfdfKernelTest extends KernelTestBase {

  /**
   * Test Xfdf.
   */
  public function test() {
    // Random input values.
    $key = $this->randomMachineName();
    $value = $this->randomString() . '"';
    $fields = [
      $key => $value,
    ];

    $expected = '<?xml version="1.0" encoding="UTF-8"?>
<xfdf xmlns="http://ns.adobe.com/xfdf/" xml:space="preserve"><fields><field name="' . $key . '"><value>' . htmlspecialchars($value, ENT_NOQUOTES) . '</value></field></fields><ids modified="1234"/></xfdf>
';

    $actual = Xfdf::createString($fields);
    // Make the timestamp always be the same.
    $actual = preg_replace('/ modified="\d+"/', ' modified="1234"', $actual);

    $this->assertSame($expected, $actual);
  }

}
