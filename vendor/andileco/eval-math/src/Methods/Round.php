<?php

namespace Andileco\Util\EvalMath\Methods;


class Round extends AbstractMethod
{
    protected $name = 'round';

    public function evaluate(...$args)
    {
        if (!isset($args[1])) {
            return round($args[0]);
        }

        return round($args[0], $args[1]);
    }
}