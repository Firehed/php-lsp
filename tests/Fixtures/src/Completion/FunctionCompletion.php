<?php

declare(strict_types=1);

namespace Fixtures\Completion;

/**
 * Adds two numbers.
 */
function calculateSum(int $a, int $b): int
{
    return $a + $b;
}

function getConfig(): Config
{
    return new Config();
}

class FunctionCompletionTriggers
{
    public function triggerBuiltinFunction(): void
    {
        $x = arr/*|builtin_function*/
    }

    public function triggerFunctionReturnChain(): void
    {
        $config = getConfig();
        $config->/*|function_return_chain*/
    }
}
