<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Index\DeclarationScanner;
use PhpParser\Node\Stmt;
use ReflectionException;
use ReflectionFunction;

final class DefaultFunctionRepository implements FunctionRepository
{
    public function __construct(private readonly DeclarationScanner $scanner)
    {
    }

    public function get(string $functionName, array $ast): ?FunctionInfo
    {
        $node = $this->findFunctionInAst($functionName, $ast);
        if ($node !== null) {
            return FunctionInfo::fromNode($node);
        }

        try {
            return FunctionInfo::fromReflection(new ReflectionFunction($functionName));
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Match a user-defined function whose short name or fully-qualified
     * (namespaced) name equals the query.
     *
     * @param array<Stmt> $ast
     */
    private function findFunctionInAst(string $functionName, array $ast): ?Stmt\Function_
    {
        foreach ($this->scanner->scan($ast)->functions as $declaration) {
            $name = $declaration->name;
            if ($name->shortName === $functionName || $name->fullyQualifiedName() === $functionName) {
                return $declaration->node;
            }
        }

        return null;
    }
}
