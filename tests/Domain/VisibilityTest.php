<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Visibility::class)]
class VisibilityTest extends TestCase
{
    /**
     * @return array<string, array{Visibility, Visibility, bool}>
     */
    public static function accessibilityProvider(): array
    {
        return [
            'public from public' => [Visibility::Public, Visibility::Public, true],
            'public from protected' => [Visibility::Public, Visibility::Protected, true],
            'public from private' => [Visibility::Public, Visibility::Private, true],
            'protected from public' => [Visibility::Protected, Visibility::Public, false],
            'protected from protected' => [Visibility::Protected, Visibility::Protected, true],
            'protected from private' => [Visibility::Protected, Visibility::Private, true],
            'private from public' => [Visibility::Private, Visibility::Public, false],
            'private from protected' => [Visibility::Private, Visibility::Protected, false],
            'private from private' => [Visibility::Private, Visibility::Private, true],
        ];
    }

    #[DataProvider('accessibilityProvider')]
    public function testIsAccessibleFrom(
        Visibility $member,
        Visibility $required,
        bool $expected,
    ): void {
        self::assertSame($expected, $member->isAccessibleFrom($required));
    }

    /**
     * @return array<string, array{Visibility, string}>
     * @codeCoverageIgnore
     */
    public static function formatProvider(): array
    {
        return [
            'private' => [Visibility::Private, 'private'],
            'protected' => [Visibility::Protected, 'protected'],
            'public' => [Visibility::Public, 'public'],
        ];
    }

    #[DataProvider('formatProvider')]
    public function testFormat(Visibility $visibility, string $expected): void
    {
        self::assertSame($expected, $visibility->format());
    }
}
