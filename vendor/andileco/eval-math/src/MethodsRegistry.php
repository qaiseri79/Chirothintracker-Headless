<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath;


use ArrayAccess;
use Countable;
use RuntimeException;
use Andileco\Util\EvalMath\Methods\AbstractMethod;

class MethodsRegistry implements ArrayAccess, Countable
{
    protected $methods = [];

    public function set(AbstractMethod $method)
    {
        $this->methods[$method->getName()] = clone $method;
        return $this;
    }

    public function unsetByName($name)
    {
        unset($this->methods[$name]);
        return $this;
    }

    /**
     * @param $name
     * @return AbstractMethod|null
     */
    public function findByName($name)
    {
        return (isset($this->methods[$name]) and $this->methods[$name] instanceof AbstractMethod) ? $this->methods[$name] : null;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->methods[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        $m = $this->findByName($offset);
        return $m ? $m->getArgumentCount() : null;
    }

    public function offsetSet($offset, $value): void
    {
        throw new RuntimeException('Use set() method instead');
    }

    public function offsetUnset($offset): void
    {
        $this->unsetByName($offset);
    }

    public function count(): int
    {
        return count($this->methods);
    }
}
