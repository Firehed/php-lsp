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
     * @return array{
     *   methods: list<array{name: string, node: Stmt\ClassMethod}>,
     *   properties: list<array{name: string, node: Stmt\Property}>,
     *   constants: list<array{name: string, node: Stmt\ClassConst}>,
     *   enumCases: list<array{name: string, node: Stmt\EnumCase}>,
     * }
     */
    public static function collect(
        Stmt\Class_|Stmt\Enum_|Stmt\Interface_|Stmt\Trait_|null $classNode,
        VisibilityFilter $visibility,
        MemberFilter $memberFilter,
    ): array {
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
                if (self::matchesFilters($stmt, $visibility, $memberFilter)) {
                    $methods[] = ['name' => $stmt->name->toString(), 'node' => $stmt];
                }
            }

            if ($stmt instanceof Stmt\Property) {
                if (self::matchesFilters($stmt, $visibility, $memberFilter)) {
                    foreach ($stmt->props as $prop) {
                        $properties[] = ['name' => $prop->name->toString(), 'node' => $stmt];
                    }
                }
            }

            if ($stmt instanceof Stmt\ClassConst) {
                if ($memberFilter !== MemberFilter::Instance) {
                    if (self::matchesVisibility($stmt, $visibility)) {
                        foreach ($stmt->consts as $const) {
                            $constants[] = ['name' => $const->name->toString(), 'node' => $stmt];
                        }
                    }
                }
            }

            if ($stmt instanceof Stmt\EnumCase) {
                if ($memberFilter !== MemberFilter::Instance) {
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

    private static function matchesFilters(
        Stmt\ClassMethod|Stmt\Property $stmt,
        VisibilityFilter $visibility,
        MemberFilter $memberFilter,
    ): bool {
        if (!self::matchesVisibility($stmt, $visibility)) {
            return false;
        }

        return match ($memberFilter) {
            MemberFilter::Instance => !$stmt->isStatic(),
            MemberFilter::Static => $stmt->isStatic(),
            MemberFilter::Both => true,
        };
    }

    private static function matchesVisibility(
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
