<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Exception;

use Exception;

class AbstractEvalMathException extends Exception implements EvalMathException
{
    protected $error = '';

    public function __construct(array $parameters=[])
    {
        $message = strtr($this->error, $parameters);
        parent::__construct($message);
    }
}