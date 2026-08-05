<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\QualifiedName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(QualifiedName::class)]
final class QualifiedNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function fullyQualifiedNames(): iterable
    {
        yield 'namespaced' => ['Psr\Log\LoggerInterface', 'Psr\Log', 'LoggerInterface'];
        yield 'single segment namespace' => ['Psr\Log', 'Psr', 'Log'];
        yield 'global' => ['strlen', '', 'strlen'];
        yield 'leading separator' => ['\Psr\Log\LoggerInterface', 'Psr\Log', 'LoggerInterface'];
        yield 'leading separator, global' => ['\strlen', '', 'strlen'];
    }

    #[DataProvider('fullyQualifiedNames')]
    public function testFromFullyQualifiedSplitsOnTheFinalSeparator(
        string $fqn,
        string $expectedNamespace,
        string $expectedShortName,
    ): void {
        $name = QualifiedName::fromFullyQualified($fqn);

        self::assertSame($expectedNamespace, $name->namespace);
        self::assertSame($expectedShortName, $name->shortName);
        // A leading separator is spelling, not identity: the round-trip drops it.
        self::assertSame(ltrim($fqn, '\\'), $name->fullyQualifiedName());
    }

    public function testGlobalNameHasAnEmptyNamespace(): void
    {
        $name = new QualifiedName('', 'strlen');

        self::assertSame('strlen', $name->fullyQualifiedName());
    }
}
