<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Completion\VisibilityFilter;
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

        return self::findMethodInClassNode(
            $classNode,
            $methodName,
            $ast,
            $classLocator,
            $parser,
            VisibilityFilter::All,
        );
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
    ): ?PropertyInfo {
        $classNode = ClassFinder::findWithLocator($className, $ast, $classLocator, $parser);
        if ($classNode === null) {
            return null;
        }

        return self::findPropertyInClassNode(
            $classNode,
            $propertyName,
            $ast,
            $classLocator,
            $parser,
            VisibilityFilter::All,
        );
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
        VisibilityFilter $visibility,
    ): ?Stmt\ClassMethod {
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === $methodName) {
                if (!$visibility->allowsMethod($stmt)) {
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
                            VisibilityFilter::All,
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
                    VisibilityFilter::PublicProtected,
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
        VisibilityFilter $visibility,
    ): ?PropertyInfo {
        foreach (PropertyCollector::collect($classNode) as $property) {
            if ($property->name === $propertyName) {
                if (!$visibility->allowsProperty($property)) {
                    continue;
                }
                return $property;
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
                            VisibilityFilter::All,
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
                    VisibilityFilter::PublicProtected,
                );
            }
        }

        return null;
    }

    private static function getParentClassName(
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $classNode,
    ): ?string {
        if (!$classNode instanceof Stmt\Class_) {
            return null;
        }
        return ScopeFinder::resolveExtendsName($classNode);
    }
}
