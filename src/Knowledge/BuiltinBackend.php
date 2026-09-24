<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Attribute;
use BackedEnum;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\InternalConstantSet;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\PrefixSearch;
use Firehed\PhpLsp\Index\Symbol;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * The lowest-precedence {@see SymbolSourceInterface}: the symbols built into PHP and its
 * loaded extensions, described through reflection. It is consulted only after the
 * open-document and disk backends, so a name either of them can resolve never
 * reaches reflection (RFC 1 §5.3).
 *
 * Built-ins are fixed for a given target environment, so a {@see CachingSymbolSource}
 * in front of this backend remembers them (RFC 1 §5.3). This backend is reflection-backed and therefore describes the
 * *server's* runtime, not the project's target — a known §4.7 gap deferred to Step 5
 * (Plan 0002 §5); the interim treats every reflected built-in as available.
 *
 * Enumeration draws on one derived index of the internal symbols, built once on
 * first use and shared by every read: `childrenOf` reads its namespace grouping
 * and `search` reads its per-kind grouping, so the two answers can never disagree
 * about which names count as built-in (RFC 1 §4.2).
 *
 * Two things make that index less obvious than it looks:
 *
 * - `get_declared_classes()` reports every class loaded in *this* process, which
 *   includes the language server and its own vendored dependencies. Only the
 *   internal ones are built-ins.
 * - Built-ins are not all global. `Random\Randomizer` and classes contributed by
 *   extensions live in namespaces, so each symbol is filed under the namespace
 *   its reflected name actually carries.
 *
 * Prefix search for class-likes is empty: a bare prefix would surface built-ins
 * that do not resolve unqualified in the file's namespace, which is auto-import,
 * a separate concern. Functions and constants are searched through the same
 * derived index, which is bounded and already in memory.
 */
final class BuiltinBackend implements SymbolSourceInterface
{
    use LooksUpByKindTrait;

    /** @var array<string, NamespaceContents>|null Lowercase namespace -> contents */
    private ?array $byNamespace = null;

    /** @var array<string, list<CatalogSymbol>>|null Kind name -> symbols */
    private ?array $symbolsByKind = null;

    public function __construct(
        private readonly InternalConstantSet $constants,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $this->byNamespace ??= NamespaceContents::indexByNamespace($this->internalSymbols());

        return $this->byNamespace[$namespace->normalize()] ?? new NamespaceContents();
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        return PrefixSearch::filter(
            $this->symbolsOfKind($kind),
            $prefix,
            $kind,
            static fn(): Location => new Location('', 0, 0, 0, 0),
        );
    }

    private function classInfo(QualifiedName $name): ?SymbolInfoInterface
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
        $className = ClasslikeName::fromFullyQualified($class->getName());
        $parentClass = $class->getParentClass();

        return new ClassInfo(
            name: $className,
            kind: $this->determineKindFromReflection($class),
            isAbstract: $class->isAbstract() && !$class->isInterface(),
            isFinal: $class->isFinal(),
            isReadonly: $class->isReadOnly(),
            isAttribute: $class->getAttributes(Attribute::class) !== [],
            parent: $parentClass !== false
                ? ClasslikeName::fromFullyQualified($parentClass->getName())
                : null,
            interfaces: $this->extractInterfaces($class),
            // No built-in class uses traits; getTraitNames() would return [] anyway.
            traits: [],
            methods: $this->extractMethods($class, $className),
            properties: $this->extractProperties($class, $className),
            constants: $this->extractConstants($class, $className),
            enumCases: $this->extractEnumCases($class, $className),
            docblock: $class->getDocComment() !== false ? $class->getDocComment() : null,
            file: $class->getFileName() !== false ? $class->getFileName() : null,
            line: $class->getStartLine() !== false ? $class->getStartLine() : null,
        );
    }

    private function constantInfo(QualifiedName $name): ?SymbolInfoInterface
    {
        $fqn = $name->fullyQualifiedName();
        if (!$this->constants->contains($fqn)) {
            return null;
        }

        return new ConstantInfo(
            name: new ConstantName($name),
            type: null,
            docblock: null,
            file: null,
            line: null,
        );
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     */
    private function determineKindFromReflection(ReflectionClass $class): ClassKind
    {
        // PHP 8.5 ships no built-in traits, so no Trait_ branch is needed here.
        if ($class->isInterface()) {
            return ClassKind::Interface_;
        }
        if ($class->isEnum()) {
            return ClassKind::Enum_;
        }
        return ClassKind::Class_;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, ClasslikeConstantInfo>
     */
    private function extractConstants(ReflectionClass $class, ClasslikeName $className): array
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
            $constants[$name] = new ClasslikeConstantInfo(
                name: new ClasslikeConstantName($className, $name),
                // No built-in class ships non-public constants; hard-code Public.
                visibility: Visibility::Public,
                isFinal: $constant->isFinal(),
                type: TypeFactory::fromReflection($constant->getType()),
                docblock: $constant->getDocComment() !== false ? $constant->getDocComment() : null,
                file: $class->getFileName() !== false ? $class->getFileName() : null,
                line: null,
            );
        }

        return $constants;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, EnumCaseInfo>
     */
    private function extractEnumCases(ReflectionClass $class, ClasslikeName $className): array
    {
        if (!$class->isEnum()) {
            return [];
        }

        $cases = [];

        foreach ($class->getReflectionConstants() as $constant) {
            $name = $constant->getName();
            $enumCase = $constant->getValue();
            $backingValue = $enumCase instanceof BackedEnum ? $enumCase->value : null;

            $cases[$name] = new EnumCaseInfo(
                name: new EnumCaseName($className, $name),
                backingValue: $backingValue,
                docblock: $constant->getDocComment() !== false ? $constant->getDocComment() : null,
                file: $class->getFileName() !== false ? $class->getFileName() : null,
                line: null,
            );
        }

        return $cases;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return list<ClasslikeName>
     */
    private function extractInterfaces(ReflectionClass $class): array
    {
        $interfaces = [];
        $parent = $class->getParentClass();
        $parentInterfaces = $parent !== false ? $parent->getInterfaceNames() : [];

        foreach ($class->getInterfaceNames() as $interfaceName) {
            // Only include directly implemented interfaces, not inherited ones.
            if (!in_array($interfaceName, $parentInterfaces, true)) {
                $interfaces[] = ClasslikeName::fromFullyQualified($interfaceName);
            }
        }

        return $interfaces;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return array<string, MethodInfo>
     */
    private function extractMethods(ReflectionClass $class, ClasslikeName $className): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $name = $method->getName();
            $methods[$name] = new MethodInfo(
                name: new MethodName($className, $name),
                visibility: $this->visibilityFromReflectionMethod($method),
                isStatic: $method->isStatic(),
                isAbstract: $method->isAbstract(),
                isFinal: $method->isFinal(),
                parameters: $this->extractParameters($method),
                returnType: TypeFactory::fromReflection($method->getReturnType()),
                docblock: $method->getDocComment() !== false ? $method->getDocComment() : null,
                file: $method->getFileName() !== false ? $method->getFileName() : null,
                line: $method->getStartLine() !== false ? $method->getStartLine() : null,
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
    private function extractProperties(ReflectionClass $class, ClasslikeName $className): array
    {
        $properties = [];

        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $name = $property->getName();
            $properties[$name] = new PropertyInfo(
                name: new PropertyName($className, $name),
                visibility: $this->visibilityFromReflectionProperty($property),
                isStatic: $property->isStatic(),
                isReadonly: $property->isReadOnly(),
                isPromoted: $property->isPromoted(),
                type: TypeFactory::fromReflection($property->getType()),
                docblock: $property->getDocComment() !== false ? $property->getDocComment() : null,
                file: $class->getFileName() !== false ? $class->getFileName() : null,
                line: null,
            );
        }

        return $properties;
    }

    private function functionInfo(QualifiedName $name): ?SymbolInfoInterface
    {
        try {
            $reflection = new ReflectionFunction($name->fullyQualifiedName());
        } catch (ReflectionException) {
            return null;
        }

        // Reflection also sees the server's own dependencies; enumeration filters
        // those out, so lookup must too (RFC 1 §4.2).
        if (!$reflection->isInternal()) {
            return null;
        }

        $parameters = [];
        foreach ($reflection->getParameters() as $param) {
            $parameters[] = $this->parameterFromReflection($param);
        }

        return new FunctionInfo(
            name: FunctionName::fromFullyQualified($reflection->getName()),
            parameters: $parameters,
            returnType: TypeFactory::fromReflection($reflection->getReturnType()),
            docblock: $reflection->getDocComment() !== false ? $reflection->getDocComment() : null,
            file: $reflection->getFileName() !== false ? $reflection->getFileName() : null,
            line: $reflection->getStartLine() !== false ? $reflection->getStartLine() : null,
        );
    }

    /**
     * @return list<CatalogSymbol>
     */
    private function internalSymbols(): array
    {
        $symbols = [];

        $classLikes = [
            ...get_declared_classes(),
            ...get_declared_interfaces(),
            ...get_declared_traits(),
        ];
        foreach ($classLikes as $classLike) {
            if ((new ReflectionClass($classLike))->isInternal()) {
                $symbols[] = new CatalogSymbol($classLike, NameKind::ClassLike);
            }
        }

        foreach (get_defined_functions()['internal'] as $function) {
            $symbols[] = new CatalogSymbol($function, NameKind::Function_);
        }

        foreach (array_keys($this->constants->all()) as $constant) {
            $symbols[] = new CatalogSymbol($constant, NameKind::Constant);
        }

        return $symbols;
    }

    private function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface
    {
        return match ($kind) {
            NameKind::ClassLike => $this->classInfo($name),
            NameKind::Constant => $this->constantInfo($name),
            NameKind::Function_ => $this->functionInfo($name),
        };
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

    /**
     * @return list<CatalogSymbol>
     */
    private function symbolsOfKind(NameKind $kind): array
    {
        if ($this->symbolsByKind === null) {
            $this->symbolsByKind = [];
            foreach (NameKind::cases() as $k) {
                $this->symbolsByKind[$k->name] = [];
            }
            foreach ($this->internalSymbols() as $symbol) {
                $this->symbolsByKind[$symbol->kind->name][] = $symbol;
            }
        }
        return $this->symbolsByKind[$kind->name];
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
