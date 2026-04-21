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
     *   properties: list<PropertyInfo>,
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
        $constants = [];
        $enumCases = [];

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod) {
                if (self::matchesFilters($stmt, $visibility, $memberFilter)) {
                    $methods[] = ['name' => $stmt->name->toString(), 'node' => $stmt];
                }
            }

            if ($stmt instanceof Stmt\ClassConst) {
                if ($memberFilter !== MemberFilter::Instance && $visibility->allowsConstant($stmt)) {
                    foreach ($stmt->consts as $const) {
                        $constants[] = ['name' => $const->name->toString(), 'node' => $stmt];
                    }
                }
            }

            if ($stmt instanceof Stmt\EnumCase) {
                if ($memberFilter !== MemberFilter::Instance) {
                    $enumCases[] = ['name' => $stmt->name->toString(), 'node' => $stmt];
                }
            }
        }

        $properties = self::filterProperties(
            PropertyCollector::collect($classNode),
            $visibility,
            $memberFilter,
        );

        return [
            'methods' => $methods,
            'properties' => $properties,
            'constants' => $constants,
            'enumCases' => $enumCases,
        ];
    }

    private static function matchesFilters(
        Stmt\ClassMethod $stmt,
        VisibilityFilter $visibility,
        MemberFilter $memberFilter,
    ): bool {
        return $visibility->allowsMethod($stmt) && $memberFilter->matches($stmt->isStatic());
    }

    /**
     * @param list<PropertyInfo> $properties
     * @return list<PropertyInfo>
     */
    private static function filterProperties(
        array $properties,
        VisibilityFilter $visibility,
        MemberFilter $memberFilter,
    ): array {
        return array_values(array_filter(
            $properties,
            static fn(PropertyInfo $p) =>
                $visibility->allowsProperty($p) && $memberFilter->matches($p->isStatic),
        ));
    }
}
