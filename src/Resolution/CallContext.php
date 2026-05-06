<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

/**
 * Context for signature help and named argument completion.
 * Captures the callable being invoked and which parameter is active.
 */
final readonly class CallContext
{
    /**
     * @param list<string> $usedParameterNames Names already used as named arguments
     */
    public function __construct(
        public ResolvedCallable $callable,
        public int $activeParameterIndex,
        public array $usedParameterNames,
    ) {
    }
}
