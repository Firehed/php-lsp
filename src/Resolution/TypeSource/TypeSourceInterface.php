<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\TypeSource;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\GlobalConstantName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Type;

/**
 * Source-blind resolution of typed positions on symbols. A consumer asks for
 * the type at a named position; the answer's origin (native declaration,
 * docblock, reflection) is not visible.
 */
interface TypeSourceInterface
{
    public function forClassConstant(ClassName $class, ConstantName $constant): ?Type;

    public function forGlobalConstant(GlobalConstantName $constant): ?Type;

    public function forFunctionReturn(FunctionName $function): ?Type;

    public function forMethodReturn(ClassName $class, MethodName $method): ?Type;

    public function forFunctionParameter(FunctionName $function, string $parameter): ?Type;

    public function forMethodParameter(ClassName $class, MethodName $method, string $parameter): ?Type;

    public function forProperty(ClassName $class, PropertyName $property): ?Type;
}
