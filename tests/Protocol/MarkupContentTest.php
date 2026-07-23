<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\MarkupContent;
use Firehed\PhpLsp\Protocol\MarkupKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkupContent::class)]
class MarkupContentTest extends TestCase
{
    /**
     * @param array{kind: string, value: string} $expected
     */
    #[DataProvider('cases')]
    public function testToArrayRendersTheLspLiteral(MarkupKind $kind, string $value, array $expected): void
    {
        self::assertSame(
            $expected,
            (new MarkupContent($kind, $value))->toArray(),
            'MarkupContent serializes as the [LSP] {kind, value} literal',
        );
    }

    /**
     * @codeCoverageIgnore
     *
     * @return iterable<string, array{MarkupKind, string, array{kind: string, value: string}}>
     */
    public static function cases(): iterable
    {
        yield 'markdown' => [
            MarkupKind::Markdown,
            '```php' . "\n" . 'function f(): void' . "\n" . '```',
            ['kind' => 'markdown', 'value' => '```php' . "\n" . 'function f(): void' . "\n" . '```'],
        ];
        yield 'plaintext' => [
            MarkupKind::PlainText,
            'function f(): void',
            ['kind' => 'plaintext', 'value' => 'function f(): void'],
        ];
    }
}
