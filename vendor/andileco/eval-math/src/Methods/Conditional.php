<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Methods;


class Conditional extends AbstractMethod
{
    protected $name='if';
    protected $argument_count = [3];

    public function evaluate(...$args)
    {
        return $args[0] ? $args[1] : $args[2];
    }
}