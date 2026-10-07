<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2019
 * @license BSD 2.0
 */

namespace Andileco\Util\EvalMath\Tests;

use PHPUnit\Framework\TestCase;
use Andileco\Util\EvalMath\EvalMath;
use Andileco\Util\EvalMath\Exception\AbstractEvalMathException;
use Andileco\Util\EvalMath\Exception\BuiltInFunctionRedefinitionException;
use Andileco\Util\EvalMath\Exception\ConstantAssignmentException;
use Andileco\Util\EvalMath\Exception\DivisionByZeroException;
use Andileco\Util\EvalMath\Exception\ExpectingTokenException;
use Andileco\Util\EvalMath\Exception\IllegalCharacterException;
use Andileco\Util\EvalMath\Exception\InternalErrorException;
use Andileco\Util\EvalMath\Exception\InvalidArgumentCountException;
use Andileco\Util\EvalMath\Exception\OperatorLacksOperandException;
use Andileco\Util\EvalMath\Exception\OperatorRequiredException;
use Andileco\Util\EvalMath\Exception\UndefinedVariableException;
use Andileco\Util\EvalMath\Exception\UndefinedVariableInFunctionDefinitionException;
use Andileco\Util\EvalMath\Exception\UnexpectedOperatorException;
use Andileco\Util\EvalMath\Exception\UnexpectedTokenException;

class EvalMathExceptionsTest extends TestCase
{
    private $EvalMath;

    protected function setUp()
    {
        $this->EvalMath = new EvalMath();
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testBuiltInFunctionRedefinition()
    {
        $this->expectException(BuiltInFunctionRedefinitionException::class);
        $this->EvalMath->evaluate('cos(x) = x*2');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testConstantAssignmentException()
    {
        $this->expectException(ConstantAssignmentException::class);
        $this->EvalMath->evaluate('pi = 123');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testDivisionByZero()
    {
        $this->expectException(DivisionByZeroException::class);
        $this->EvalMath->evaluate('5/0');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testExpectingTokenException()
    {
        $this->expectException(ExpectingTokenException::class);
        $this->EvalMath->evaluate('p = 6*(3+1');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testIllegalCharacterException()
    {
        $this->expectException(IllegalCharacterException::class);
        $this->EvalMath->evaluate('$v = 5');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testInternalErrorException()
    {
        $this->expectException(InternalErrorException::class);
        $this->EvalMath->e('k=');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testInvalidArgumentCountException()
    {
        $this->expectException(InvalidArgumentCountException::class);
        $this->EvalMath->e('a=sin(5,7,6)');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testInvalidArgumentFuncCountException()
    {
        $this->expectException(InvalidArgumentCountException::class);
        $this->EvalMath->e('iif(1)');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testInvalidArgumentFuncCountException2()
    {
        $this->expectException(InvalidArgumentCountException::class);
        $this->EvalMath->e('iif()');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testInvalidArgumentUserFuncCountException()
    {
        $this->expectException(InvalidArgumentCountException::class);
        $this->EvalMath->e('f(a,b) = a+b');
        $this->EvalMath->e('f(1)');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testOperatorLacksOperandException()
    {
        $this->expectException(OperatorLacksOperandException::class);
        $this->EvalMath->e('k=5+');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testOperatorRequiredException()
    {
        $this->expectException(OperatorRequiredException::class);
        $this->EvalMath->e('a=5(7+1)');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testUndefinedVariableException()
    {
        $this->expectException(UndefinedVariableException::class);
        $this->EvalMath->e('b=a+1');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testUndefinedVariableInFunctionDefinitionException()
    {
        $this->expectException(UndefinedVariableInFunctionDefinitionException::class);
        $this->EvalMath->e('f(a) = b*3');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testUnexpectedOperatorException()
    {
        $this->expectException(UnexpectedOperatorException::class);
        $this->EvalMath->e('1 + * 2');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testUnexpectedTokenException()
    {
        $this->expectException(UnexpectedTokenException::class);
        $this->EvalMath->e('a=(3+)');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testUnexpectedClosingPrenticeException()
    {
        $this->expectException(UnexpectedTokenException::class);
        $this->EvalMath->e('a=5)');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testUnexpectedComma()
    {
        $this->expectException(UnexpectedTokenException::class);
        $this->EvalMath->e('a=5,3)');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testUnexpectedComma2()
    {
        $this->expectException(UnexpectedTokenException::class);
        $this->EvalMath->e('a=sin(0.3),3)');
    }

    /**
     * @throws AbstractEvalMathException
     */
    public function testUnexpectedComma3()
    {
        $this->expectException(UnexpectedTokenException::class);
        $this->EvalMath->e('a=sin(0.1,)');
    }
}