<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Tests;


use PHPUnit\Framework\TestCase;
use RuntimeException;
use Andileco\Util\EvalMath\Methods\Maximum;
use Andileco\Util\EvalMath\MethodsRegistry;

class MethodRegistryTest extends TestCase
{
    private $registry;

    protected function setUp()
    {
        $this->registry = new MethodsRegistry();
    }

    public function testSetFindUnset()
    {
        $r = $this->registry->set(new Maximum);
        $this->assertInstanceOf(MethodsRegistry::class, $r);

        $c = $this->registry->findByName('max');
        $this->assertInstanceOf(Maximum::class, $c);
        $this->assertTrue(isset($this->registry['max']));

        $c = $this->registry['max'];
        $this->assertTrue(is_array($c));
//        $this->assertInstanceOf(Maximum::class, $c);

        $this->assertEquals(1, $this->registry->count());
        unset($this->registry['max']);
        $this->assertEquals(0, $this->registry->count());


        $r = $this->registry->unsetByName('max');
        $this->assertInstanceOf(MethodsRegistry::class, $r);
    }

    /**
     * @expectedException RuntimeException
     */
    public function testOffsetSetException()
    {
        $this->registry['max'] = new Maximum();
    }

    public function testAliases()
    {
        $this->registry = new MethodsRegistry();
        $this->registry->set(new Maximum())->set((new Maximum)->setName('mmaaxx'));
        $this->assertEquals(2, $this->registry->count());
        $this->assertTrue(isset($this->registry['max']));
        $this->assertTrue(isset($this->registry['mmaaxx']));
    }
}