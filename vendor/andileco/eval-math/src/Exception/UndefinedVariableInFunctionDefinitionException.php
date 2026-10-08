<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Exception;


class UndefinedVariableInFunctionDefinitionException extends AbstractEvalMathException
{
    protected $message = 'Undefined variable :token in function definition';
}