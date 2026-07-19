<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\FunctionInfo;
use PhpParser\Node\Stmt;

/**
 * Repository for resolving standalone function metadata from multiple sources.
 */
interface FunctionRepository
{
    /**
     * Resolve a function by name.
     *
     * Resolution order: user-defined function in the given AST -> reflection
     * fallback for built-ins.
     *
     * @param array<Stmt> $ast
     */
    public function get(string $functionName, array $ast): ?FunctionInfo;
}
