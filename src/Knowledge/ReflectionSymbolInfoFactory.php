<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Attribute;
use BackedEnum;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\InternalConstantSet;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * The reflection counterpart of {@see DeclarationSymbolInfoFactory}, describing the
 * *server's* runtime rather than the project's target — the §4.7 gap deferred to
 * Step 5.
 */
final readonly class ReflectionSymbolInfoFactory
{
    public function __construct(
        private InternalConstantSet $constants = new InternalConstantSet(),
    ) {
    }

    public function fromReflection(QualifiedName $name, NameKind $kind): ?SymbolInfo
    {
        return match ($kind) {
            NameKind::ClassLike => $this->classInfo($name),
            NameKind::Constant => $this->constantInfo($name),
            NameKind::Function_ => $this->functionInfo($name),
        };
    }

    private function classInfo(QualifiedName $name): ?SymbolInfo
    {
        $fqn = $name->fullyQualifiedName();

        // All three probes narrow the name to a `class-string`, which is why
        // this kind cannot use the sibling's try/catch.
        if (!class_exists($fqn) && !interface_exists($fqn) && !trait_exists($fqn)) {
            return null;
        }

        $rc = new ReflectionClass($fqn);
        if (!$rc->isInternal()) {
            return null;
        }

        return $this->classInfoFromReflection($rc);
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     */
    private function classInfoFromReflection(ReflectionClass $class): ClassInfo
    {
        $className = TypeFactory::className($class->getName());
        $parentClass = $class->getParentClass();

        return new ClassInfo(
            name: $className,
            kind: $this->determineKindFromReflection($class),
            isAbstract: $class->isAbstract() && !$class->isInterface(),
            isFinal: $class->isFinal(),
            isReadonly: $class->isReadOnly(),
            isAttribute: $class->getAttributes(Attribute::class) !== [],
            parent: $parentClass !== false
                ? TypeFactory::className($parentClass->getName())
                : null,
            interfaces: $this->extractInterfaces($class),
            traits: $this->extractTraits($class),
            methods: $this->extractMethods($class, $className),
            properties: $this->extractProperties($class, $className),
            constants: $this->extractConstants($class, $className),
            enumCases: $this->extractEnumCases($class, $className),
            docblock: $class->getDocComment() !== false ? $class->getDocComment() : null,
            file: $class->getFileName() !== false ? $class->getFileName() : null,
            line: $class->getStartLine() !== false ? $class->getStartLine() : null,
        );
    }

    private function constantInfo(QualifiedName $name): ?SymbolInfo
    {
        $fqn = $name->fullyQualifiedName();
        if (!$this->constants->contains($fqn)) {
            return null;
        }

        return new ConstantInfo(
            name: new ConstantName($name->shortName),
            visibility: Visibility::Public,
            isFinal: true,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: null,
        );
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

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, ConstantInfo>
     */
    private function extractConstants(ReflectionClass $class, ClassName $className): array
    {
        $constants = [];

        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            if ($constant->isEnumCase()) {
                continue;
            }

            $name = $constant->getName();
            $constants[$name] = new ConstantInfo(
                name: new ConstantName($name),
                visibility: $this->visibilityFromReflectionConstant($constant),
                isFinal: $constant->isFinal(),
                type: TypeFactory::fromReflection($constant->getType()),
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
    private function extractEnumCases(ReflectionClass $class, ClassName $className): array
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
            $backingValue = $enumCase instanceof BackedEnum ? $enumCase->value : null;

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

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return list<ClassName>
     */
    private function extractInterfaces(ReflectionClass $class): array
    {
        $interfaces = [];
        $parent = $class->getParentClass();
        $parentInterfaces = $parent !== false ? $parent->getInterfaceNames() : [];

        foreach ($class->getInterfaceNames() as $interfaceName) {
            // Only include directly implemented interfaces, not inherited ones.
            if (!in_array($interfaceName, $parentInterfaces, true)) {
                $interfaces[] = TypeFactory::className($interfaceName);
            }
        }

        return $interfaces;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, MethodInfo>
     */
    private function extractMethods(ReflectionClass $class, ClassName $className): array
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
                parameters: $this->extractParameters($method),
                returnType: TypeFactory::fromReflection($method->getReturnType()),
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
    private function extractParameters(ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $params[] = $this->parameterFromReflection($param);
        }
        return $params;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, PropertyInfo>
     */
    private function extractProperties(ReflectionClass $class, ClassName $className): array
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
                type: TypeFactory::fromReflection($property->getType()),
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
     * @return list<ClassName>
     */
    private function extractTraits(ReflectionClass $class): array
    {
        $traits = [];

        foreach ($class->getTraitNames() as $traitName) {
            $traits[] = TypeFactory::className($traitName);
        }

        return $traits;
    }

    private function functionInfo(QualifiedName $name): ?SymbolInfo
    {
        try {
            $reflection = new ReflectionFunction($name->fullyQualifiedName());
        } catch (ReflectionException) {
            return null;
        }

        // Reflection also sees the server's own dependencies; enumeration filters
        // those out, so lookup must too (RFC 1 §4.2).
        return $reflection->isInternal() ? FunctionInfo::fromReflection($reflection) : null;
    }

    private function parameterFromReflection(ReflectionParameter $param): ParameterInfo
    {
        $defaultValue = null;
        if ($param->isDefaultValueAvailable()) {
            $defaultValue = self::formatReflectionDefault($param->getDefaultValue());
        }

        return new ParameterInfo(
            name: $param->getName(),
            type: TypeFactory::fromReflection($param->getType()),
            hasDefault: $param->isDefaultValueAvailable(),
            defaultValue: $defaultValue,
            position: $param->getPosition(),
            isVariadic: $param->isVariadic(),
            isPassedByReference: $param->isPassedByReference(),
        );
    }

    private static function formatReflectionDefault(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === []) {
            return '[]';
        }
        return var_export($value, true);
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
}
