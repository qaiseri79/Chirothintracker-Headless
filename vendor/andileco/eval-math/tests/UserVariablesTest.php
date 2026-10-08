<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Tests;


use PHPUnit\Framework\TestCase;
use Andileco\Util\EvalMath\EvalMath;

class UserVariablesTest extends TestCase
{
    public function testVariablesDump()
    {
        $e = new EvalMath();
        $this->assertEmpty($e->vars());

        $e->evaluate('a=1');
        $this->assertArrayHasKey('a',$e->vars());
        $this->assertEquals('1', $e->vars()['a']);
    }
}