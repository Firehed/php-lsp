<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\EnumImplicits;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\TraitAlias;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use PhpParser\Modifiers;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

final class DefaultClassInfoFactory
{
    /**
     * @deprecated sweep: source-context factory
     */
    #[\Deprecated('sweep: source-context factory')]
    public function fromAstNode(Stmt\ClassLike $node, string $uri): ClassInfo
    {
        $className = $this->resolveClassName($node);
        $filePath = FileUri::toPath($uri);
        $traitUse = $this->extractTraitUse($node);

        return new ClassInfo(
            name: $className,
            kind: $this->determineKind($node),
            isAbstract: $node instanceof Stmt\Class_ && $node->isAbstract(),
            isFinal: $node instanceof Stmt\Class_ && $node->isFinal(),
            isReadonly: $node instanceof Stmt\Class_ && $node->isReadonly(),
            isAttribute: $this->isAttributeNode($node),
            parent: $this->resolveParent($node),
            interfaces: $this->extractInterfaces($node),
            traits: $traitUse['traits'],
            methods: $this->extractMethods($node, $className, $filePath),
            properties: $this->extractProperties($node, $className, $filePath),
            constants: $this->extractConstants($node, $className, $filePath),
            enumCases: $this->extractEnumCases($node, $className, $filePath),
            docblock: $node->getDocComment()?->getText(),
            file: $filePath,
            line: $node->getStartLine(),
            traitExclusions: $traitUse['exclusions'],
            traitAliases: $traitUse['aliases'],
        );
    }

    private function resolveClassName(Stmt\ClassLike $node): ClassName
    {
        $fqn = LateBindingKeyword::Self->resolveIn($node);
        if ($fqn === null) {
            throw new \InvalidArgumentException('Cannot create ClassInfo for anonymous class');
        }
        return TypeFactory::className($fqn);
    }

    private function determineKind(Stmt\ClassLike $node): ClassKind
    {
        return match (true) {
            $node instanceof Stmt\Interface_ => ClassKind::Interface_,
            $node instanceof Stmt\Trait_ => ClassKind::Trait_,
            $node instanceof Stmt\Enum_ => ClassKind::Enum_,
            default => ClassKind::Class_,
        };
    }

    /**
     * A class is a PHP attribute when it is itself declared with `#[Attribute]`.
     * Only classes can be attributes; interfaces, traits, and enums cannot.
     */
    private function isAttributeNode(Stmt\ClassLike $node): bool
    {
        if (!$node instanceof Stmt\Class_) {
            return false;
        }

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($this->resolveNameToClassName($attr->name)->fqn === \Attribute::class) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolveParent(Stmt\ClassLike $node): ?ClassName
    {
        if (!$node instanceof Stmt\Class_ || $node->extends === null) {
            return null;
        }

        return $this->resolveNameToClassName($node->extends);
    }

    /**
     * @return list<ClassName>
     */
    private function extractInterfaces(Stmt\ClassLike $node): array
    {
        $interfaces = [];

        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $interfaces[] = $this->resolveNameToClassName($interface);
            }
        }

        if ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $interfaces[] = $this->resolveNameToClassName($interface);
            }
        }

        if ($node instanceof Stmt\Enum_) {
            $interfaces = array_merge($interfaces, EnumImplicits::interfaces($node->scalarType !== null));
        }

        return $interfaces;
    }

    /**
     * @return array{traits: list<ClassName>, exclusions: array<string, list<string>>, aliases: list<TraitAlias>}
     */
    private function extractTraitUse(Stmt\ClassLike $node): array
    {
        $traits = [];
        $exclusions = [];
        $aliases = [];

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $trait) {
                $traits[] = $this->resolveNameToClassName($trait);
            }
            foreach ($stmt->adaptations as $adaptation) {
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                    $method = $adaptation->method->toString();
                    foreach ($adaptation->insteadof as $loser) {
                        $exclusions[$this->resolveNameToClassName($loser)->fqn][] = $method;
                    }
                    continue;
                }
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                    $aliases[] = new TraitAlias(
                        trait: $adaptation->trait !== null
                            ? $this->resolveNameToClassName($adaptation->trait)
                            : null,
                        method: $adaptation->method->toString(),
                        newName: $adaptation->newName?->toString(),
                        newVisibility: $adaptation->newModifier !== null
                            ? $this->visibilityFromFlags($adaptation->newModifier)
                            : null,
                    );
                }
            }
        }

        return ['traits' => $traits, 'exclusions' => $exclusions, 'aliases' => $aliases];
    }

    private function resolveNameToClassName(\PhpParser\Node\Name $name): ClassName
    {
        $resolved = $name->getAttribute('resolvedName');
        /** @var class-string */
        $fqn = $resolved instanceof \PhpParser\Node\Name\FullyQualified
            ? $resolved->toString()
            : $name->toString();
        return TypeFactory::className($fqn);
    }

    /**
     * @return array<string, MethodInfo>
     */
    private function extractMethods(Stmt\ClassLike $node, ClassName $className, string $filePath): array
    {
        $methods = [];
        $parentClass = $this->resolveParent($node);

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassMethod) {
                continue;
            }

            $name = $stmt->name->toString();
            $methods[$name] = new MethodInfo(
                name: new MethodName($name),
                visibility: $this->visibilityFromFlags($stmt->flags),
                isStatic: $stmt->isStatic(),
                isAbstract: $stmt->isAbstract(),
                isFinal: $stmt->isFinal(),
                parameters: $this->extractParameters($stmt->params, $className, $parentClass),
                returnType: TypeFactory::fromNode(
                    $stmt->returnType,
                    $className->fqn,
                    $parentClass?->fqn,
                    preserveLateBinding: true,
                ),
                docblock: $stmt->getDocComment()?->getText(),
                file: $filePath,
                line: $stmt->getStartLine(),
                declaringClass: $className,
            );
        }

        if ($node instanceof Stmt\Enum_) {
            $methods = array_merge($methods, EnumImplicits::methods($className, $this->enumScalarType($node)));
        }

        return $methods;
    }

    private function enumScalarType(Stmt\Enum_ $enum): ?PrimitiveType
    {
        if ($enum->scalarType === null) {
            return null;
        }

        return TypeFactory::primitive($enum->scalarType->toString());
    }

    /**
     * @param array<Param> $params
     * @return list<ParameterInfo>
     */
    private function extractParameters(array $params, ClassName $className, ?ClassName $parentClass): array
    {
        $result = [];
        foreach ($params as $position => $param) {
            $info = ParameterInfo::fromNode($param, $position, $className->fqn, $parentClass?->fqn);
            if ($info !== null) {
                $result[] = $info;
            }
        }
        return $result;
    }

    /**
     * @return array<string, PropertyInfo>
     */
    private function extractProperties(Stmt\ClassLike $node, ClassName $className, string $filePath): array
    {
        $properties = [];

        if ($node instanceof Stmt\Enum_) {
            $properties = EnumImplicits::properties($className, $this->enumScalarType($node));
        }
        $parentClass = $this->resolveParent($node);

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    $properties[$name] = new PropertyInfo(
                        name: new PropertyName($name),
                        visibility: $this->visibilityFromFlags($stmt->flags),
                        isStatic: $stmt->isStatic(),
                        isReadonly: $stmt->isReadonly(),
                        isPromoted: false,
                        type: TypeFactory::fromNode($stmt->type, $className->fqn, $parentClass?->fqn),
                        docblock: $stmt->getDocComment()?->getText(),
                        file: $filePath,
                        line: $stmt->getStartLine(),
                        declaringClass: $className,
                    );
                }
            }

            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    if (!$this->isPromotedProperty($param)) {
                        continue;
                    }
                    if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                        continue;
                    }

                    $name = $param->var->name;
                    $properties[$name] = new PropertyInfo(
                        name: new PropertyName($name),
                        visibility: $this->visibilityFromFlags($param->flags),
                        isStatic: false,
                        isReadonly: ($param->flags & Modifiers::READONLY) !== 0,
                        isPromoted: true,
                        type: TypeFactory::fromNode($param->type, $className->fqn, $parentClass?->fqn),
                        docblock: $param->getDocComment()?->getText(),
                        file: $filePath,
                        line: $param->getStartLine(),
                        declaringClass: $className,
                    );
                }
            }
        }

        return $properties;
    }

    private function isPromotedProperty(Param $param): bool
    {
        return ($param->flags & Modifiers::VISIBILITY_MASK) !== 0;
    }

    /**
     * @return array<string, ConstantInfo>
     */
    private function extractConstants(Stmt\ClassLike $node, ClassName $className, string $filePath): array
    {
        $constants = [];
        $parentClass = $this->resolveParent($node);

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassConst) {
                continue;
            }

            foreach ($stmt->consts as $const) {
                $name = $const->name->toString();
                $constants[$name] = new ConstantInfo(
                    name: new ConstantName($name),
                    visibility: $this->visibilityFromFlags($stmt->flags),
                    isFinal: $stmt->isFinal(),
                    type: TypeFactory::fromNode($stmt->type, $className->fqn, $parentClass?->fqn),
                    docblock: $stmt->getDocComment()?->getText(),
                    file: $filePath,
                    line: $stmt->getStartLine(),
                    declaringClass: $className,
                );
            }
        }

        return $constants;
    }

    /**
     * @return array<string, EnumCaseInfo>
     */
    private function extractEnumCases(Stmt\ClassLike $node, ClassName $className, string $filePath): array
    {
        if (!$node instanceof Stmt\Enum_) {
            return [];
        }

        $cases = [];

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\EnumCase) {
                continue;
            }

            $name = $stmt->name->toString();
            $cases[$name] = new EnumCaseInfo(
                name: new EnumCaseName($name),
                backingValue: $this->extractEnumCaseBackingValue($stmt),
                docblock: $stmt->getDocComment()?->getText(),
                file: $filePath,
                line: $stmt->getStartLine(),
                declaringClass: $className,
            );
        }

        return $cases;
    }

    private function extractEnumCaseBackingValue(Stmt\EnumCase $case): int|string|null
    {
        $expr = $case->expr;
        if ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return $expr->value;
        }
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return $expr->value;
        }
        return null;
    }

    private function visibilityFromFlags(int $flags): Visibility
    {
        if (($flags & Modifiers::PRIVATE) !== 0) {
            return Visibility::Private;
        }
        if (($flags & Modifiers::PROTECTED) !== 0) {
            return Visibility::Protected;
        }
        return Visibility::Public;
    }
}
