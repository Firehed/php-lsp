<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\TypeSource;

use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * Source-blind resolution of typed positions on symbols. A consumer asks for
 * the type at a named position; the answer's origin (native declaration,
 * docblock, reflection) is not visible.
 */
interface TypeSourceInterface
{
    public function forClassConstant(ClasslikeConstantName $constant): ?TypeInterface;

    public function forGlobalConstant(ConstantName $constant): ?TypeInterface;

    public function forFunctionReturn(FunctionName $function): ?TypeInterface;

    public function forMethodReturn(MethodName $method): ?TypeInterface;

    public function forFunctionParameter(FunctionName $function, string $parameter): ?TypeInterface;

    public function forMethodParameter(MethodName $method, string $parameter): ?TypeInterface;

    public function forProperty(PropertyName $property): ?TypeInterface;
}
