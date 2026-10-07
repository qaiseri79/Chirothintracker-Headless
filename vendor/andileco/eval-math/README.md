
Composer/Packagist version of EvalMath by Miles Kaufman
Copyright (C) 2005 Miles Kaufmann <http://www.twmagic.com/>
NAME
----
    EvalMath - safely evaluate math expressions
  
DESCRIPTION
-----------
    Use the EvalMath class when you want to evaluate mathematical expressions 
    from untrusted sources.  You can define your own variables and functions,
    which are stored in the object.  Try it, it's fun!
        
SYNOPSIS
--------
    `$m = new EvalMath;`
    
    `// basic evaluation:`
    `$result = $m->evaluate('2+2');`
    
    `// supports: order of operation; parentheses; negation; built-in functions`
    `$result = $m->evaluate('-8(5/2)^2*(1-sqrt(4))-8');`
    
    `// create your own variables`
    `$m->evaluate('a = e^(ln(pi))');`
    
    `// or functions`
    `$m->evaluate('f(x,y) = x^2 + y^2 - 2x*y + 1');`
    
    `// and then use them`
    `$result = $m->evaluate('3*f(42,a)');`
    
    `// use methods (calc functions)
    `$m->evaluate('1+max(2,3)') // => 4`
    `$m->evaluate('if(1=2, 2+2, 5+5') // => 10

METHODS
-------
    `$m->evaluate($expr)`
        Evaluates the expression and returns the result.  If an error occurs,
        prints a warning and returns false.  If $expr is a function assignment,
        returns true on success.
    
    `$m->e($expr)`
        A synonym for $m->evaluate().
    
    `$m->vars()`
        Returns an associative array of all user-defined variables and values.
        
    `$m->funcs()`
        Returns an array of all user-defined functions.
        
CALC METHODS (CALC FUNCTIONS)
-----------------------------

- `max(n...,m)` returns one of given arguments with maximal value
- `min(n...,m)` returns one of given arguments with minimal value
- `if(expr, true_value, false_value)` (has a `iif` synonym) returns `true_value` of `false_value` depends of `expr` evaluation result
- `round(n,m)` returns rounded n value with m precision

CREATE YOUR OWN CUSTOM CALC METHODS (CALC FUNCTIONS)
-----------------------------------------------
    You can create custom classes, that implements custom calc methods.
    
TODO
----
- Improve documentation
- Ability to add custom operators
- More tests

CREDITS AND COPYRIGHTS
----------------------
This software integrates several libraries and patches by:

- Original EvalMath library, (C) 2005 Miles Kaufmann <http://www.twmagic.com/>
- Modifications for 'calc functions' by Moodle, <https://github.com/moodle/moodle>
- Composer/Packagist version, (C) Daniel Bojdo, <https://github.com/dbojdo>
   