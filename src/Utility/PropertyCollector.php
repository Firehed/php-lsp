<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use PhpParser\Modifiers;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

final class PropertyCollector
{
    /**
     * Collect all properties from a class node, including promoted properties.
     *
     * @return list<PropertyInfo>
     */
    public static function collect(
        Stmt\Class_|Stmt\Enum_|Stmt\Interface_|Stmt\Trait_|null $classNode,
    ): array {
        if ($classNode === null) {
            return [];
        }

        $properties = [];

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $properties[] = new PropertyInfo(
                        name: $prop->name->toString(),
                        type: $stmt->type,
                        docComment: $stmt->getDocComment(),
                        startLine: $stmt->getStartLine(),
                        endLine: $stmt->getEndLine(),
                        isStatic: $stmt->isStatic(),
                        isReadonly: $stmt->isReadonly(),
                        isPublic: $stmt->isPublic(),
                        isProtected: $stmt->isProtected(),
                        isPrivate: $stmt->isPrivate(),
                    );
                }
            }

            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    if (!self::isPromotedProperty($param)) {
                        continue;
                    }
                    if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                        continue;
                    }

                    $properties[] = new PropertyInfo(
                        name: $param->var->name,
                        type: $param->type,
                        docComment: $param->getDocComment(),
                        startLine: $param->getStartLine(),
                        endLine: $param->getEndLine(),
                        isStatic: false,
                        isReadonly: ($param->flags & Modifiers::READONLY) !== 0,
                        isPublic: ($param->flags & Modifiers::PUBLIC) !== 0,
                        isProtected: ($param->flags & Modifiers::PROTECTED) !== 0,
                        isPrivate: ($param->flags & Modifiers::PRIVATE) !== 0,
                    );
                }
            }
        }

        return $properties;
    }

    private static function isPromotedProperty(Param $param): bool
    {
        return ($param->flags & Modifiers::VISIBILITY_MASK) !== 0;
    }
}
