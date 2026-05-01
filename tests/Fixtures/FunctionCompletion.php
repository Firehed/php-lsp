<?php

/**
 * Adds two numbers.
 */
function calculateSum(int $a, int $b): int
{
    return $a + $b;
}

$result = calc/*|user_defined_function*/

class FunctionReturnConfig
{
    public function get(string $key): mixed
    {
        return null;
    }
}

function getFunctionReturnConfig(): FunctionReturnConfig
{
    return new FunctionReturnConfig();
}

function triggerFunctionReturnChain(): void
{
    $config = getFunctionReturnConfig();
    $config->/*|function_return_chain*/
}
