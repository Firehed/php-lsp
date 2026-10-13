<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * Builds minimal domain value objects for tests that need symbols without a real
 * parse. Each builder takes only what a test may vary; everything else is empty.
 *
 * The `declared*` pair wraps the info in the {@see DeclaredSymbol} the kind-agnostic
 * write and lookup paths take, so a test states the kind once rather than picking a
 * per-kind slot.
 */
trait BuildsSymbolInfoTrait
{
    /**
     * @param list<string> $interfaces
     */
    private static function declaredClass(
        string $fqn,
        ?string $parent = null,
        array $interfaces = [],
        ?string $file = null,
    ): DeclaredSymbol {
        return new DeclaredSymbol(
            QualifiedName::fromFullyQualified($fqn),
            NameKind::ClassLike,
            self::classInfo($fqn, parent: $parent, interfaces: $interfaces, file: $file),
        );
    }

    private static function declaredConstant(string $fqn, ?string $file = null): DeclaredSymbol
    {
        $name = QualifiedName::fromFullyQualified($fqn);

        return new DeclaredSymbol($name, NameKind::Constant, self::constantInfo($name, $file));
    }

    private static function declaredFunction(string $fqn, ?string $file = null): DeclaredSymbol
    {
        $name = QualifiedName::fromFullyQualified($fqn);

        return new DeclaredSymbol($name, NameKind::Function_, self::functionInfo($name, $file));
    }

    /**
     * @param list<string> $interfaces
     */
    private static function classInfo(
        string $fqn,
        ClassKind $kind = ClassKind::Class_,
        ?string $parent = null,
        array $interfaces = [],
        ?string $file = null,
        bool $isAbstract = false,
        bool $isFinal = false,
        bool $isAttribute = false,
    ): ClassInfo {
        return new ClassInfo(
            self::className($fqn),
            $kind,
            isAbstract: $isAbstract,
            isFinal: $isFinal,
            isReadonly: false,
            isAttribute: $isAttribute,
            parent: $parent === null ? null : self::className($parent),
            interfaces: array_map(self::className(...), $interfaces),
            traits: [],
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: null,
            file: $file,
            line: null,
        );
    }

    private static function constantInfo(
        QualifiedName $name,
        ?string $file = null,
        ?TypeInterface $type = null,
    ): ConstantInfo {
        return new ConstantInfo(
            name: new ConstantName($name),
            type: $type,
            docblock: null,
            file: $file,
            line: 1,
        );
    }

    /**
     * @param list<ParameterInfo> $parameters
     */
    private static function functionInfo(
        QualifiedName $name,
        ?string $file = null,
        array $parameters = [],
        ?TypeInterface $returnType = null,
    ): FunctionInfo {
        return new FunctionInfo(new FunctionName($name), $parameters, $returnType, null, $file, 1);
    }

    private static function methodInfo(
        ClasslikeName $owner,
        string $name,
        ?TypeInterface $returnType = null,
        bool $isStatic = false,
    ): MethodInfo {
        return new MethodInfo(
            name: new MethodName($owner, $name),
            visibility: Visibility::Public,
            isStatic: $isStatic,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: $returnType,
            docblock: null,
            file: null,
            line: null,
        );
    }

    private static function parameterInfo(string $name, ?TypeInterface $type = null, int $position = 0): ParameterInfo
    {
        return new ParameterInfo($name, $type, null, $position, false, false);
    }

    private static function propertyInfo(
        ClasslikeName $owner,
        string $name,
        ?TypeInterface $type = null,
        Visibility $visibility = Visibility::Public,
    ): PropertyInfo {
        return new PropertyInfo(
            name: new PropertyName($owner, $name),
            visibility: $visibility,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: $type,
            docblock: null,
            file: null,
            line: null,
        );
    }

    /**
     * Fixture and virtual names live outside PHPStan's autoload path, so they are
     * not seen as class-strings; only the FQN string is read, so the concession is
     * harmless and confined here.
     */
    private static function className(string $fqn): ClasslikeName
    {
        return ClasslikeName::fromFullyQualified($fqn);
    }
}
