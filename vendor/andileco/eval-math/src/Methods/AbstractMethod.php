<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Methods;


abstract class AbstractMethod
{
    protected $name = '';

    protected $argument_count = [-1];

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
        return clone $this;
    }

    public function getArgumentCount()
    {
        return $this->argument_count;
    }

    abstract public function evaluate(...$args);
}