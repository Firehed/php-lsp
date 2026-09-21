<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\TypeSource;

use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;

/**
 * Answers {@see TypeSourceInterface} for a symbol PHP itself (native code or reflection)
 * declares. Class-members walk the inheritance graph through
 * {@see MemberResolverInterface}, so a method inherited from a supertype resolves at the
 * subclass just as PHP would find it.
 *
 * The answer's origin (open-document AST, workspace file, vendored file,
 * reflection) is a {@see SymbolSourceInterface} concern and does not surface here.
 */
final readonly class NativeTypeSource implements TypeSourceInterface
{
    public function __construct(
        private SymbolSourceInterface $symbols,
        private MemberResolverInterface $members,
    ) {
    }

    public function forClassConstant(ClasslikeConstantName $constant): ?TypeInterface
    {
        return $this->members->findConstant($constant->owner, $constant->name, Visibility::Private)?->type;
    }

    public function forGlobalConstant(ConstantName $constant): ?TypeInterface
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

    public function forMethodParameter(MethodName $method, string $parameter): ?TypeInterface
    {
        $info = $this->members->findMethod($method->owner, $method->name, Visibility::Private);
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

    public function forMethodReturn(MethodName $method): ?TypeInterface
    {
        return $this->members->findMethod($method->owner, $method->name, Visibility::Private)?->returnType;
    }

    public function forProperty(PropertyName $property): ?TypeInterface
    {
        return $this->members->findProperty($property->owner, $property->name, Visibility::Private)?->type;
    }
}
