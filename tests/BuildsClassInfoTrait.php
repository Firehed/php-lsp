<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;

/**
 * Builds minimal {@see ClassInfo} value objects for tests that need class-likes
 * without a real parse — only the identity and, where a subtype walk is under
 * test, the parent and interface edges.
 */
trait BuildsClassInfoTrait
{
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
