<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Tests;

use PHPUnit\Framework\TestCase;
use Andileco\Util\EvalMath\Methods\Conditional;
use Andileco\Util\EvalMath\Methods\Maximum;
use Andileco\Util\EvalMath\Methods\Minimum;
use Andileco\Util\EvalMath\Methods\Round;

class MethodsTest extends TestCase
{
    public function testConditional()
    {
        $m = new Conditional();
        $this->assertEquals('if', $m->getName());
        $this->assertEquals([3], $m->getArgumentCount());
        $this->assertEquals(1, $m->evaluate(true, 1, 0));
        $this->assertEquals(0, $m->evaluate(false, 1, 0));
    }

    public function testMaximum()
    {
        $m = new Maximum();
        $this->assertEquals('max', $m->getName());
        $this->assertEquals([-1], $m->getArgumentCount());
        $this->assertEquals(1, $m->evaluate(1, 0));
        $this->assertEquals(10, $m->evaluate(false, 1, 0, 10));
        $this->assertEquals(1, $m->evaluate(1));
    }

    public function testMinimum()
    {
        $m = new Minimum();
        $this->assertEquals('min', $m->getName());
        $this->assertEquals([-1], $m->getArgumentCount());
        $this->assertEquals(0, $m->evaluate(1, 0));
        $this->assertEquals(0, $m->evaluate(false, 1, 0, 10));
        $this->assertEquals(1, $m->evaluate(1));
    }

    public function testRound()
    {
        $m = new Round();
        $this->assertEquals('round', $m->getName());
        $this->assertEquals([-1], $m->getArgumentCount());

        $this->assertEquals(2, $m->evaluate(1.5321));
        $this->assertEquals(1, $m->evaluate(1.4321));
        $this->assertEquals(1, $m->evaluate(1.4921));

        $this->assertEquals(git add .1.57, $m->evaluate(1.5678, 2));
        $this->assertEquals(1.568, $m->evaluate(1.5678, 3));
        $this->assertEquals(1.5678, $m->evaluate(1.5678, 4));

        $this->assertEquals(1.54, $m->evaluate(1.5378, 2));
        $this->assertEquals(1.538, $m->evaluate(1.5378, 3));
        $this->assertEquals(1.5378, $m->evaluate(1.5378, 4));

        $this->assertEquals(1.53, $m->evaluate(1.5321, 2));
        $this->assertEquals(1.532, $m->evaluate(1.5321, 3));
        $this->assertEquals(1.5321, $m->evaluate(1.5321, 4));
    }
}