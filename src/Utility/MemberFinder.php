<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use PhpParser\Node\Stmt;

final class MemberFinder
{
    /**
     * Find a method by name in a class, traversing the inheritance hierarchy.
     *
     * Searches in PHP method resolution order: class -> traits -> parent.
     * Private methods are not inherited from parent classes.
     *
     * @param array<Stmt> $ast
     */
    public static function findMethod(
        string $className,
        string $methodName,
        array $ast,
        ?ComposerClassLocator $classLocator,
        ParserService $parser,
    ): ?Stmt\ClassMethod {
        $classNode = ClassFinder::findWithLocator($className, $ast, $classLocator, $parser);
        if ($classNode === null) {
            return null;
        }

        return self::findMethodInClassNode($classNode, $methodName, $ast, $classLocator, $parser, false);
    }

    /**
     * Find a property by name in a class, traversing the inheritance hierarchy.
     *
     * Private properties are not inherited from parent classes.
     *
     * @param array<Stmt> $ast
     */
    public static function findProperty(
        string $className,
        string $propertyName,
        array $ast,
        ?ComposerClassLocator $classLocator,
        ParserService $parser,
    ): ?Stmt\Property {
        $classNode = ClassFinder::findWithLocator($className, $ast, $classLocator, $parser);
        if ($classNode === null) {
            return null;
        }

        return self::findPropertyInClassNode($classNode, $propertyName, $ast, $classLocator, $parser, false);
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findMethodInClassNode(
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $classNode,
        string $methodName,
        array $ast,
        ?ComposerClassLocator $classLocator,
        ParserService $parser,
        bool $excludePrivate,
    ): ?Stmt\ClassMethod {
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === $methodName) {
                if ($excludePrivate && $stmt->isPrivate()) {
                    continue;
                }
                return $stmt;
            }
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $traitName) {
                    $traitClassName = ScopeFinder::resolveName($traitName);
                    $traitNode = ClassFinder::findWithLocator($traitClassName, $ast, $classLocator, $parser);
                    if ($traitNode !== null) {
                        $traitMethod = self::findMethodInClassNode(
                            $traitNode,
                            $methodName,
                            $ast,
                            $classLocator,
                            $parser,
                            true,
                        );
                        if ($traitMethod !== null) {
                            return $traitMethod;
                        }
                    }
                }
            }
        }

        $parentName = self::getParentClassName($classNode);
        if ($parentName !== null) {
            $parentNode = ClassFinder::findWithLocator($parentName, $ast, $classLocator, $parser);
            if ($parentNode !== null) {
                return self::findMethodInClassNode(
                    $parentNode,
                    $methodName,
                    $ast,
                    $classLocator,
                    $parser,
                    true,
                );
            }
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findPropertyInClassNode(
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $classNode,
        string $propertyName,
        array $ast,
        ?ComposerClassLocator $classLocator,
        ParserService $parser,
        bool $excludePrivate,
    ): ?Stmt\Property {
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                if ($excludePrivate && $stmt->isPrivate()) {
                    continue;
                }
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() === $propertyName) {
                        return $stmt;
                    }
                }
            }
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $traitName) {
                    $traitClassName = ScopeFinder::resolveName($traitName);
                    $traitNode = ClassFinder::findWithLocator($traitClassName, $ast, $classLocator, $parser);
                    if ($traitNode !== null) {
                        $traitProperty = self::findPropertyInClassNode(
                            $traitNode,
                            $propertyName,
                            $ast,
                            $classLocator,
                            $parser,
                            true,
                        );
                        if ($traitProperty !== null) {
                            return $traitProperty;
                        }
                    }
                }
            }
        }

        $parentName = self::getParentClassName($classNode);
        if ($parentName !== null) {
            $parentNode = ClassFinder::findWithLocator($parentName, $ast, $classLocator, $parser);
            if ($parentNode !== null) {
                return self::findPropertyInClassNode(
                    $parentNode,
                    $propertyName,
                    $ast,
                    $classLocator,
                    $parser,
                    true,
                );
            }
        }

        return null;
    }

    private static function getParentClassName(
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $classNode,
    ): ?string {
        if (!$classNode instanceof Stmt\Class_ || $classNode->extends === null) {
            return null;
        }
        return ScopeFinder::resolveName($classNode->extends);
    }
}
