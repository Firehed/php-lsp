<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;

/**
 * Builds minimal domain value objects for tests that need symbols without a real
 * parse — only the identity, the declaring file where precedence is under test,
 * and, for a class-like, the parent and interface edges a subtype walk follows.
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

    private static function declaredFunction(string $fqn, ?string $file = null): DeclaredSymbol
    {
        $name = QualifiedName::fromFullyQualified($fqn);

        return new DeclaredSymbol($name, NameKind::Function_, self::functionInfo($name->shortName, $file));
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
    ): ClassInfo {
        return new ClassInfo(
            self::className($fqn),
            $kind,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            isAttribute: false,
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

    private static function functionInfo(string $shortName, ?string $file = null): FunctionInfo
    {
        return new FunctionInfo($shortName, [], null, null, $file, 1);
    }

    /**
     * Fixture and virtual names live outside PHPStan's autoload path, so they are
     * not seen as class-strings; only the FQN string is read, so the concession is
     * harmless and confined here.
     */
    private static function className(string $fqn): ClassName
    {
        /** @phpstan-ignore argument.type (virtual names are not analyzed) */
        return new ClassName($fqn);
    }
}
