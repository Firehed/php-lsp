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
     * @return array<string, array{Visibility, Visibility, Visibility}>
     * @codeCoverageIgnore
     */
    public static function atLeastProvider(): array
    {
        return [
            'private raised to protected' => [Visibility::Private, Visibility::Protected, Visibility::Protected],
            'protected floored at protected' => [Visibility::Protected, Visibility::Protected, Visibility::Protected],
            'public unaffected by protected floor' => [Visibility::Public, Visibility::Protected, Visibility::Public],
            'private raised to public' => [Visibility::Private, Visibility::Public, Visibility::Public],
            'private unaffected by private floor' => [Visibility::Private, Visibility::Private, Visibility::Private],
            'protected unaffected by private floor' => [Visibility::Protected, Visibility::Private, Visibility::Protected],
        ];
    }

    #[DataProvider('atLeastProvider')]
    public function testAtLeast(
        Visibility $visibility,
        Visibility $floor,
        Visibility $expected,
    ): void {
        self::assertSame($expected, $visibility->atLeast($floor));
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

    /**
     * @return array<string, array{string, Visibility}>
     * @codeCoverageIgnore
     */
    public static function fromStringProvider(): array
    {
        return [
            'private lowercase' => ['private', Visibility::Private],
            'private uppercase' => ['PRIVATE', Visibility::Private],
            'private mixed' => ['Private', Visibility::Private],
            'protected lowercase' => ['protected', Visibility::Protected],
            'protected uppercase' => ['PROTECTED', Visibility::Protected],
            'public lowercase' => ['public', Visibility::Public],
            'public uppercase' => ['PUBLIC', Visibility::Public],
            'empty string defaults to public' => ['', Visibility::Public],
            'unknown defaults to public' => ['unknown', Visibility::Public],
        ];
    }

    #[DataProvider('fromStringProvider')]
    public function testFromString(string $input, Visibility $expected): void
    {
        self::assertSame($expected, Visibility::fromString($input));
    }
}
