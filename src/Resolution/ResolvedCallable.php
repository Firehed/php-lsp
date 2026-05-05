<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\Type;

/**
 * A resolved callable (function or method) with parameter information.
 */
interface ResolvedCallable extends ResolvedSymbol
{
    /**
     * @return list<ParameterInfo>
     */
    public function getParameters(): array;

    public function getReturnType(): ?Type;

    /**
     * Returns the parameter at the given 0-based position, or null if out of bounds.
     */
    public function getParameterAtPosition(int $position): ?ParameterInfo;

    /**
     * Returns the parameter with the given name (without $), or null if not found.
     */
    public function getParameterByName(string $name): ?ParameterInfo;
}
