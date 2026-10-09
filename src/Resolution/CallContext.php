<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ResolvedCallableInterface;

/**
 * Context for signature help and named argument completion.
 * Captures the callable being invoked and which parameter is active.
 */
final readonly class CallContext
{
    /**
     * @param list<string> $usedParameterNames Names already used as named arguments
     * @param int $positionallyFilledCount Number of positional args before first named arg
     * @param bool $inNamedArgumentValue Whether the cursor is past a named argument's colon,
     *        where only a value can be typed
     */
    public function __construct(
        public ResolvedCallableInterface $callable,
        public int $activeParameterIndex,
        public array $usedParameterNames,
        public int $positionallyFilledCount = 0,
        public bool $inNamedArgumentValue = false,
    ) {
    }
}
