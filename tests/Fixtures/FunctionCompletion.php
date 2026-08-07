<?php

/**
 * Adds two numbers.
 */
function calculateSum(int $a, int $b): int
{
    return $a + $b;
}

// The shape a polyfill takes: declared only where the runtime lacks it, so the
// declaration is nested rather than top-level. It is still a name this file declares.
if (!function_exists('calculateProduct')) {
    /**
     * Multiplies two numbers.
     */
    function calculateProduct(int $a, int $b): int
    {
        return $a * $b;
    }
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
