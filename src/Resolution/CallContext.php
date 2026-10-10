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
     * @param bool $inNamedArgumentValue Whether the argument being typed is named and the
     *        cursor is past `name:` (read as the name and the character after it), where
     *        only a value can be typed
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
