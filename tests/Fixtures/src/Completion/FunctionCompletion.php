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

// A polyfill declares itself only where the runtime lacks it, so the declaration is
// nested rather than top-level. It is still a name this file declares, and the
// completion surface must offer it (RFC 1 §4.2).
if (!function_exists(__NAMESPACE__ . '\calculateProduct')) {
    /**
     * Multiplies two numbers.
     */
    function calculateProduct(int $a, int $b): int
    {
        return $a * $b;
    }
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

    public function triggerUserFunction(): void
    {
        $y = calc/*|user_function*/
    }
}
