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
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Utility\TypeFormatter;
use PhpParser\Modifiers;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;

final class DefaultClassInfoFactory implements ClassInfoFactory
{
    public function fromAstNode(Stmt\ClassLike $node, string $uri): ClassInfo
    {
        $className = $this->resolveClassName($node);
        $filePath = $this->uriToPath($uri);

        return new ClassInfo(
            name: $className,
            kind: $this->determineKind($node),
            isAbstract: $node instanceof Stmt\Class_ && $node->isAbstract(),
            isFinal: $node instanceof Stmt\Class_ && $node->isFinal(),
            isReadonly: $node instanceof Stmt\Class_ && $node->isReadonly(),
            parent: $this->resolveParent($node),
            interfaces: $this->extractInterfaces($node),
            traits: $this->extractTraits($node),
            methods: $this->extractMethods($node, $className, $filePath),
            properties: $this->extractProperties($node, $className, $filePath),
            constants: $this->extractConstants($node, $className, $filePath),
            enumCases: $this->extractEnumCases($node, $className, $filePath),
            docblock: $node->getDocComment()?->getText(),
            file: $filePath,
            line: $node->getStartLine(),
        );
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     */
    public function fromReflection(ReflectionClass $class): ClassInfo
    {
        $className = new ClassName($class->getName());
        $parentClass = $class->getParentClass();

        return new ClassInfo(
            name: $className,
            kind: $this->determineKindFromReflection($class),
            isAbstract: $class->isAbstract() && !$class->isInterface(),
            isFinal: $class->isFinal(),
            isReadonly: $class->isReadOnly(),
            parent: $parentClass !== false
                ? new ClassName($parentClass->getName())
                : null,
            interfaces: $this->extractInterfacesFromReflection($class),
            traits: $this->extractTraitsFromReflection($class),
            methods: $this->extractMethodsFromReflection($class, $className),
            properties: $this->extractPropertiesFromReflection($class, $className),
            constants: $this->extractConstantsFromReflection($class, $className),
            enumCases: $this->extractEnumCasesFromReflection($class, $className),
            docblock: $class->getDocComment() !== false ? $class->getDocComment() : null,
            file: $class->getFileName() !== false ? $class->getFileName() : null,
            line: $class->getStartLine() !== false ? $class->getStartLine() : null,
        );
    }

    private function resolveClassName(Stmt\ClassLike $node): ClassName
    {
        if ($node->name === null) {
            throw new \InvalidArgumentException('Cannot create ClassInfo for anonymous class');
        }

        if (isset($node->namespacedName)) {
            /** @var class-string */
            $fqn = $node->namespacedName->toString();
            return new ClassName($fqn);
        }

        /** @var class-string */
        $fqn = $node->name->toString();
        return new ClassName($fqn);
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
     * @template T of object
     * @param ReflectionClass<T> $class
     */
    private function determineKindFromReflection(ReflectionClass $class): ClassKind
    {
        if ($class->isInterface()) {
            return ClassKind::Interface_;
        }
        if ($class->isTrait()) {
            return ClassKind::Trait_;
        }
        if ($class->isEnum()) {
            return ClassKind::Enum_;
        }
        return ClassKind::Class_;
    }

    private function resolveParent(Stmt\ClassLike $node): ?ClassName
    {
        if (!$node instanceof Stmt\Class_) {
            return null;
        }

        $extends = $node->extends;
        if ($extends === null) {
            return null;
        }

        $resolved = $extends->getAttribute('resolvedName');
        if ($resolved instanceof \PhpParser\Node\Name\FullyQualified) {
            /** @var class-string */
            $fqn = $resolved->toString();
            return new ClassName($fqn);
        }

        /** @var class-string */
        $fqn = $extends->toString();
        return new ClassName($fqn);
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

        return $interfaces;
    }

    /**
     * @return list<ClassName>
     */
    private function extractTraits(Stmt\ClassLike $node): array
    {
        $traits = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = $this->resolveNameToClassName($trait);
                }
            }
        }

        return $traits;
    }

    private function resolveNameToClassName(\PhpParser\Node\Name $name): ClassName
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof \PhpParser\Node\Name\FullyQualified) {
            /** @var class-string */
            $fqn = $resolved->toString();
            return new ClassName($fqn);
        }

        /** @var class-string */
        $fqn = $name->toString();
        return new ClassName($fqn);
    }

    /**
     * @return array<string, MethodInfo>
     */
    private function extractMethods(Stmt\ClassLike $node, ClassName $className, string $filePath): array
    {
        $methods = [];

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
                parameters: $this->extractParameters($stmt->params),
                returnType: TypeFormatter::formatNode($stmt->returnType),
                docblock: $stmt->getDocComment()?->getText(),
                file: $filePath,
                line: $stmt->getStartLine(),
                declaringClass: $className,
            );
        }

        // Add built-in enum methods
        if ($node instanceof Stmt\Enum_) {
            $methods = array_merge($methods, $this->getEnumBuiltinMethods($node, $className));
        }

        return $methods;
    }

    /**
     * @return array<string, MethodInfo>
     */
    private function getEnumBuiltinMethods(Stmt\Enum_ $enum, ClassName $className): array
    {
        $methods = [];

        // cases() is available on all enums
        $methods['cases'] = new MethodInfo(
            name: new MethodName('cases'),
            visibility: Visibility::Public,
            isStatic: true,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: 'array',
            docblock: null,
            file: null,
            line: null,
            declaringClass: $className,
        );

        // from() and tryFrom() are only available on backed enums
        if ($enum->scalarType !== null) {
            $scalarType = $enum->scalarType->toString();

            $methods['from'] = new MethodInfo(
                name: new MethodName('from'),
                visibility: Visibility::Public,
                isStatic: true,
                isAbstract: false,
                isFinal: false,
                parameters: [
                    new ParameterInfo(
                        name: 'value',
                        type: $scalarType,
                        hasDefault: false,
                        isVariadic: false,
                        isPassedByReference: false,
                    ),
                ],
                returnType: 'static',
                docblock: null,
                file: null,
                line: null,
                declaringClass: $className,
            );

            $methods['tryFrom'] = new MethodInfo(
                name: new MethodName('tryFrom'),
                visibility: Visibility::Public,
                isStatic: true,
                isAbstract: false,
                isFinal: false,
                parameters: [
                    new ParameterInfo(
                        name: 'value',
                        type: $scalarType,
                        hasDefault: false,
                        isVariadic: false,
                        isPassedByReference: false,
                    ),
                ],
                returnType: '?static',
                docblock: null,
                file: null,
                line: null,
                declaringClass: $className,
            );
        }

        return $methods;
    }

    /**
     * @param array<Param> $params
     * @return list<ParameterInfo>
     */
    private function extractParameters(array $params): array
    {
        $result = [];
        foreach ($params as $param) {
            $info = ParameterInfo::fromNode($param);
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
                        type: TypeFormatter::formatNode($stmt->type),
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
                        type: TypeFormatter::formatNode($param->type),
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
                    type: TypeFormatter::formatNode($stmt->type),
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

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return list<ClassName>
     */
    private function extractInterfacesFromReflection(ReflectionClass $class): array
    {
        $interfaces = [];
        $parent = $class->getParentClass();
        $parentInterfaces = $parent !== false ? $parent->getInterfaceNames() : [];

        foreach ($class->getInterfaceNames() as $interfaceName) {
            // Only include directly implemented interfaces, not inherited ones
            if (!in_array($interfaceName, $parentInterfaces, true)) {
                $interfaces[] = new ClassName($interfaceName);
            }
        }

        return $interfaces;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return list<ClassName>
     */
    private function extractTraitsFromReflection(ReflectionClass $class): array
    {
        $traits = [];

        foreach ($class->getTraitNames() as $traitName) {
            $traits[] = new ClassName($traitName);
        }

        return $traits;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, MethodInfo>
     */
    private function extractMethodsFromReflection(ReflectionClass $class, ClassName $className): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $name = $method->getName();
            $methods[$name] = new MethodInfo(
                name: new MethodName($name),
                visibility: $this->visibilityFromReflectionMethod($method),
                isStatic: $method->isStatic(),
                isAbstract: $method->isAbstract(),
                isFinal: $method->isFinal(),
                parameters: $this->extractParametersFromReflection($method),
                returnType: $method->getReturnType() !== null
                    ? TypeFormatter::formatReflection($method->getReturnType())
                    : null,
                docblock: $method->getDocComment() !== false ? $method->getDocComment() : null,
                file: $method->getFileName() !== false ? $method->getFileName() : null,
                line: $method->getStartLine() !== false ? $method->getStartLine() : null,
                declaringClass: $className,
            );
        }

        return $methods;
    }

    /**
     * @return list<ParameterInfo>
     */
    private function extractParametersFromReflection(ReflectionMethod $method): array
    {
        $result = [];

        foreach ($method->getParameters() as $param) {
            $result[] = new ParameterInfo(
                name: $param->getName(),
                type: $param->getType() !== null
                    ? TypeFormatter::formatReflection($param->getType())
                    : null,
                hasDefault: $param->isDefaultValueAvailable(),
                isVariadic: $param->isVariadic(),
                isPassedByReference: $param->isPassedByReference(),
            );
        }

        return $result;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, PropertyInfo>
     */
    private function extractPropertiesFromReflection(ReflectionClass $class, ClassName $className): array
    {
        $properties = [];

        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $name = $property->getName();
            $properties[$name] = new PropertyInfo(
                name: new PropertyName($name),
                visibility: $this->visibilityFromReflectionProperty($property),
                isStatic: $property->isStatic(),
                isReadonly: $property->isReadOnly(),
                isPromoted: $property->isPromoted(),
                type: $property->getType() !== null
                    ? TypeFormatter::formatReflection($property->getType())
                    : null,
                docblock: $property->getDocComment() !== false ? $property->getDocComment() : null,
                file: $class->getFileName() !== false ? $class->getFileName() : null,
                line: null,
                declaringClass: $className,
            );
        }

        return $properties;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, ConstantInfo>
     */
    private function extractConstantsFromReflection(ReflectionClass $class, ClassName $className): array
    {
        $constants = [];

        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $name = $constant->getName();
            $constants[$name] = new ConstantInfo(
                name: new ConstantName($name),
                visibility: $this->visibilityFromReflectionConstant($constant),
                isFinal: $constant->isFinal(),
                type: null,
                docblock: $constant->getDocComment() !== false ? $constant->getDocComment() : null,
                file: $class->getFileName() !== false ? $class->getFileName() : null,
                line: null,
                declaringClass: $className,
            );
        }

        return $constants;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, EnumCaseInfo>
     */
    private function extractEnumCasesFromReflection(ReflectionClass $class, ClassName $className): array
    {
        if (!$class->isEnum()) {
            return [];
        }

        $cases = [];

        foreach ($class->getReflectionConstants() as $constant) {
            if (!$constant->isEnumCase()) {
                continue;
            }

            $name = $constant->getName();
            $enumCase = $constant->getValue();
            $backingValue = $enumCase instanceof \BackedEnum ? $enumCase->value : null;

            $cases[$name] = new EnumCaseInfo(
                name: new EnumCaseName($name),
                backingValue: $backingValue,
                docblock: $constant->getDocComment() !== false ? $constant->getDocComment() : null,
                file: $class->getFileName() !== false ? $class->getFileName() : null,
                line: null,
                declaringClass: $className,
            );
        }

        return $cases;
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

    private function visibilityFromReflectionMethod(ReflectionMethod $method): Visibility
    {
        if ($method->isPrivate()) {
            return Visibility::Private;
        }
        if ($method->isProtected()) {
            return Visibility::Protected;
        }
        return Visibility::Public;
    }

    private function visibilityFromReflectionProperty(ReflectionProperty $property): Visibility
    {
        if ($property->isPrivate()) {
            return Visibility::Private;
        }
        if ($property->isProtected()) {
            return Visibility::Protected;
        }
        return Visibility::Public;
    }

    private function visibilityFromReflectionConstant(ReflectionClassConstant $constant): Visibility
    {
        if ($constant->isPrivate()) {
            return Visibility::Private;
        }
        if ($constant->isProtected()) {
            return Visibility::Protected;
        }
        return Visibility::Public;
    }

    private function uriToPath(string $uri): string
    {
        if (str_starts_with($uri, 'file://')) {
            return substr($uri, 7);
        }
        return $uri;
    }
}
