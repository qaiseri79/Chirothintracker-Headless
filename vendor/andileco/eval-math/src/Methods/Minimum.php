<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Methods;


class Minimum extends AbstractMethod
{
    protected $name = 'min';

    public function evaluate(...$args)
    {
        return min($args);
    }
}