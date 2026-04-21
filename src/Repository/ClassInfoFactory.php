<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use PhpParser\Node\Stmt;
use ReflectionClass;

/**
 * Creates ClassInfo domain objects from AST nodes or Reflection.
 */
interface ClassInfoFactory
{
    /**
     * @param Stmt\ClassLike $node The AST node for the class/interface/trait/enum
     * @param string $uri The file URI where this class is defined
     */
    public function fromAstNode(Stmt\ClassLike $node, string $uri): ClassInfo;

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     */
    public function fromReflection(ReflectionClass $class): ClassInfo;
}
