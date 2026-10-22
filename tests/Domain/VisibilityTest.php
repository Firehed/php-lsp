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
