<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfinedFileTest extends TestCase
{
    private const array ALLOWED = ['src/Domain/TypeFactory.php'];

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'allowed file' => ['src/Domain/TypeFactory.php', true];
        yield 'other source file' => ['src/Domain/ClassName.php', false];
        yield 'test file' => ['tests/Domain/TypeFactoryTest.php', true];
        yield 'rule test data' => ['tests/Architecture/data/constructs-type.php', false];
        yield 'source path containing tests' => ['src/tests/Foo.php', false];
        yield 'allowed path as a suffix of a deeper path' => ['src/Foo/src/Domain/TypeFactory.php', false];
    }

    #[DataProvider('paths')]
    public function testExemption(string $relativePath, bool $exempt): void
    {
        self::assertSame(
            $exempt,
            ConfinedFile::isExempt(dirname(__DIR__, 2) . '/' . $relativePath, self::ALLOWED),
        );
    }

    public function testUnnormalizedPathIsResolved(): void
    {
        self::assertTrue(ConfinedFile::isExempt(__DIR__ . '/../../src/Domain/TypeFactory.php', self::ALLOWED));
    }
}
