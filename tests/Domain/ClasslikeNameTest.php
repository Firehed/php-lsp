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
        $cn = new ClasslikeName(ClasslikeName::class);
        self::assertSame('ClasslikeName', $cn->shortName());
    }

    public function testShortNameWithoutNamespace(): void
    {
        $cn = new ClasslikeName(\stdClass::class);
        self::assertSame('stdClass', $cn->shortName());
    }

    public function testNamespaceWithNamespace(): void
    {
        $cn = new ClasslikeName(ClasslikeName::class);
        self::assertSame('Firehed\\PhpLsp\\Domain', $cn->namespace());
    }

    public function testNamespaceWithoutNamespace(): void
    {
        $cn = new ClasslikeName(\stdClass::class);
        self::assertNull($cn->namespace());
    }

    public function testEqualsTrue(): void
    {
        $a = new ClasslikeName(ClasslikeName::class);
        $b = new ClasslikeName(ClasslikeName::class);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new ClasslikeName(ClasslikeName::class);
        $b = new ClasslikeName(ClassKind::class);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsCaseInsensitive(): void
    {
        $a = new ClasslikeName(ClasslikeName::class);
        /** @var class-string $lowercased */
        $lowercased = 'firehed\\phplsp\\domain\\classlikename';
        $b = new ClasslikeName($lowercased);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsIgnoresALeadingSeparator(): void
    {
        $a = new ClasslikeName(ClasslikeName::class);
        /** @var class-string $leadingSeparator */
        $leadingSeparator = '\\' . ClasslikeName::class;
        $b = new ClasslikeName($leadingSeparator);
        self::assertTrue($a->equals($b), 'A leading separator is spelling, not identity');
    }
}
