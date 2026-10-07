<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Tests;


use PHPUnit\Framework\TestCase;
use Andileco\Util\EvalMath\EvalMath;

class UserFunctionsTest extends TestCase
{
    public function testFunctionsDump()
    {
        $e = new EvalMath();
        $this->assertEmpty($e->funcs());

        $e->e('f(x)=2*x');
        $this->assertEquals(1, count($e->funcs()));
    }
}