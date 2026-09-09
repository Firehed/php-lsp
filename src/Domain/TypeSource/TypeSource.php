<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain\TypeSource;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Type;

/**
 * Source-blind resolution of typed positions on symbols. A consumer asks for
 * the type at a named position; the answer's origin (native declaration,
 * docblock, reflection) is not visible.
 */
interface TypeSource
{
    public function forConstant(ClassName $class, ConstantName $constant): ?Type;

    public function forFunctionReturn(FunctionName $function): ?Type;

    public function forMethodReturn(ClassName $class, MethodName $method): ?Type;

    public function forParameter(
        ClassName|FunctionName $owner,
        ?MethodName $method,
        string $parameter,
    ): ?Type;

    public function forProperty(ClassName $class, PropertyName $property): ?Type;
}
