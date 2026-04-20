<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Completion\MemberFilter;
use Firehed\PhpLsp\Completion\VisibilityFilter;
use PhpParser\Node\Stmt;

final class MemberCollector
{
    /**
     * Collect class members filtered by visibility and static/instance.
     *
     * @param array<Stmt> $ast
     * @return array{
     *   methods: list<array{name: string, node: Stmt\ClassMethod}>,
     *   properties: list<array{name: string, node: Stmt\Property}>,
     *   constants: list<array{name: string, node: Stmt\ClassConst}>,
     *   enumCases: list<array{name: string, node: Stmt\EnumCase}>,
     * }
     */
    public function collect(
        string $className,
        array $ast,
        VisibilityFilter $visibility,
        MemberFilter $memberFilter,
    ): array {
        $classNode = $this->findClass($className, $ast);
        if ($classNode === null) {
            return [
                'methods' => [],
                'properties' => [],
                'constants' => [],
                'enumCases' => [],
            ];
        }

        $methods = [];
        $properties = [];
        $constants = [];
        $enumCases = [];

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod) {
                if ($this->matchesFilters($stmt, $visibility, $memberFilter)) {
                    $methods[] = ['name' => $stmt->name->toString(), 'node' => $stmt];
                }
            }

            if ($stmt instanceof Stmt\Property) {
                if ($this->matchesFilters($stmt, $visibility, $memberFilter)) {
                    foreach ($stmt->props as $prop) {
                        $properties[] = ['name' => $prop->name->toString(), 'node' => $stmt];
                    }
                }
            }

            if ($stmt instanceof Stmt\ClassConst) {
                if (
                    $memberFilter === MemberFilter::Static || $memberFilter === MemberFilter::Both
                ) {
                    if ($this->matchesVisibility($stmt, $visibility)) {
                        foreach ($stmt->consts as $const) {
                            $constants[] = ['name' => $const->name->toString(), 'node' => $stmt];
                        }
                    }
                }
            }

            if ($stmt instanceof Stmt\EnumCase) {
                if (
                    $memberFilter === MemberFilter::Static || $memberFilter === MemberFilter::Both
                ) {
                    $enumCases[] = ['name' => $stmt->name->toString(), 'node' => $stmt];
                }
            }
        }

        return [
            'methods' => $methods,
            'properties' => $properties,
            'constants' => $constants,
            'enumCases' => $enumCases,
        ];
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findClass(string $className, array $ast): Stmt\Class_|Stmt\Enum_|null
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    $result = $this->checkClassNode($nsStmt, $className);
                    if ($result !== null) {
                        return $result;
                    }
                }
            } else {
                $result = $this->checkClassNode($stmt, $className);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return null;
    }

    private function checkClassNode(Stmt $stmt, string $className): Stmt\Class_|Stmt\Enum_|null
    {
        if ($stmt instanceof Stmt\Class_ || $stmt instanceof Stmt\Enum_) {
            $fqcn = $stmt->namespacedName?->toString() ?? $stmt->name?->toString();
            if ($fqcn === $className) {
                return $stmt;
            }
        }
        return null;
    }

    private function matchesFilters(
        Stmt\ClassMethod|Stmt\Property $stmt,
        VisibilityFilter $visibility,
        MemberFilter $memberFilter,
    ): bool {
        if (!$this->matchesVisibility($stmt, $visibility)) {
            return false;
        }

        return match ($memberFilter) {
            MemberFilter::Instance => !$stmt->isStatic(),
            MemberFilter::Static => $stmt->isStatic(),
            MemberFilter::Both => true,
        };
    }

    private function matchesVisibility(
        Stmt\ClassMethod|Stmt\Property|Stmt\ClassConst $stmt,
        VisibilityFilter $visibility,
    ): bool {
        return match ($visibility) {
            VisibilityFilter::All => true,
            VisibilityFilter::PublicOnly => $stmt->isPublic(),
            VisibilityFilter::PublicProtected => $stmt->isPublic() || $stmt->isProtected(),
        };
    }
}
