<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\TypeSource;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\GlobalConstantName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;

/**
 * Answers {@see TypeSourceInterface} for a symbol PHP itself (native code or reflection)
 * declares. Class-members walk the inheritance graph through
 * {@see MemberResolver}, so a method inherited from a supertype resolves at the
 * subclass just as PHP would find it.
 *
 * The answer's origin (open-document AST, workspace file, vendored file,
 * reflection) is a {@see SymbolSourceInterface} concern and does not surface here.
 */
final readonly class NativeTypeSource implements TypeSourceInterface
{
    public function __construct(
        private SymbolSourceInterface $symbols,
        private MemberResolver $members,
    ) {
    }

    public function forClassConstant(ClassName $class, ConstantName $constant): ?TypeInterface
    {
        return $this->members->findConstant($class, $constant, Visibility::Private)?->type;
    }

    public function forGlobalConstant(GlobalConstantName $constant): ?TypeInterface
    {
        return $this->symbols->lookupConstant($constant)?->type;
    }

    public function forFunctionParameter(FunctionName $function, string $parameter): ?TypeInterface
    {
        $info = $this->symbols->lookupFunction($function);
        if ($info === null) {
            return null;
        }
        foreach ($info->parameters as $param) {
            if ($param->name === $parameter) {
                return $param->type;
            }
        }
        return null;
    }

    public function forFunctionReturn(FunctionName $function): ?TypeInterface
    {
        return $this->symbols->lookupFunction($function)?->returnType;
    }

    public function forMethodParameter(ClassName $class, MethodName $method, string $parameter): ?TypeInterface
    {
        $info = $this->members->findMethod($class, $method, Visibility::Private);
        if ($info === null) {
            return null;
        }
        foreach ($info->parameters as $param) {
            if ($param->name === $parameter) {
                return $param->type;
            }
        }
        return null;
    }

    public function forMethodReturn(ClassName $class, MethodName $method): ?TypeInterface
    {
        return $this->members->findMethod($class, $method, Visibility::Private)?->returnType;
    }

    public function forProperty(ClassName $class, PropertyName $property): ?TypeInterface
    {
        return $this->members->findProperty($class, $property, Visibility::Private)?->type;
    }
}
