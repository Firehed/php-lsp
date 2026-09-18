<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClasslikeName::class)]
class ClasslikeNameTest extends TestCase
{
    public function testShortNameWithNamespace(): void
    {
        $cn = ClasslikeName::fromFullyQualified(ClasslikeName::class);
        self::assertSame('ClasslikeName', $cn->qualifiedName->shortName);
    }

    public function testShortNameWithoutNamespace(): void
    {
        $cn = ClasslikeName::fromFullyQualified(\stdClass::class);
        self::assertSame('stdClass', $cn->qualifiedName->shortName);
    }

    public function testNamespaceWithNamespace(): void
    {
        $cn = ClasslikeName::fromFullyQualified(ClasslikeName::class);
        self::assertSame('Firehed\\PhpLsp\\Domain', $cn->qualifiedName->namespace->path);
    }

    public function testNamespaceWithoutNamespace(): void
    {
        $cn = ClasslikeName::fromFullyQualified(\stdClass::class);
        self::assertSame('', $cn->qualifiedName->namespace->path);
    }

    public function testEqualsTrue(): void
    {
        $a = ClasslikeName::fromFullyQualified(ClasslikeName::class);
        $b = ClasslikeName::fromFullyQualified(ClasslikeName::class);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = ClasslikeName::fromFullyQualified(ClasslikeName::class);
        $b = ClasslikeName::fromFullyQualified(ClassKind::class);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsCaseInsensitive(): void
    {
        $a = ClasslikeName::fromFullyQualified(ClasslikeName::class);
        /** @var class-string $lowercased */
        $lowercased = 'firehed\\phplsp\\domain\\classlikename';
        $b = ClasslikeName::fromFullyQualified($lowercased);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsIgnoresALeadingSeparator(): void
    {
        $a = ClasslikeName::fromFullyQualified(ClasslikeName::class);
        /** @var class-string $leadingSeparator */
        $leadingSeparator = '\\' . ClasslikeName::class;
        $b = ClasslikeName::fromFullyQualified($leadingSeparator);
        self::assertTrue($a->equals($b), 'A leading separator is spelling, not identity');
    }
}
