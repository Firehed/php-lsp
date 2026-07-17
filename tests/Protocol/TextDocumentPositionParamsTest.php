<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDocumentPositionParams::class)]
class TextDocumentPositionParamsTest extends TestCase
{
    public function testParsesValidParams(): void
    {
        $message = new RequestMessage(
            id: 1,
            method: 'textDocument/hover',
            params: [
                'textDocument' => ['uri' => 'file:///foo.php'],
                'position' => ['line' => 3, 'character' => 7],
            ],
        );

        $params = TextDocumentPositionParams::tryFromMessage($message);

        self::assertNotNull($params, 'A well-formed message should parse');
        self::assertSame('file:///foo.php', $params->uri);
        self::assertSame(3, $params->line);
        self::assertSame(7, $params->character);
    }

    public function testDefaultsWhenParamsAbsent(): void
    {
        $message = new RequestMessage(
            id: 1,
            method: 'textDocument/hover',
            params: null,
        );

        $params = TextDocumentPositionParams::tryFromMessage($message);

        self::assertNotNull($params, 'Absent params should fall back to defaults, not fail');
        self::assertSame('', $params->uri, 'Missing uri defaults to empty string');
        self::assertSame(0, $params->line, 'Missing line defaults to 0');
        self::assertSame(0, $params->character, 'Missing character defaults to 0');
    }

    public function testDefaultsWhenKeysAbsent(): void
    {
        $message = new RequestMessage(
            id: 1,
            method: 'textDocument/hover',
            params: [
                'textDocument' => [],
                'position' => [],
            ],
        );

        $params = TextDocumentPositionParams::tryFromMessage($message);

        self::assertNotNull($params, 'Empty sub-arrays should fall back to defaults');
        self::assertSame('', $params->uri);
        self::assertSame(0, $params->line);
        self::assertSame(0, $params->character);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    #[DataProvider('malformedParamsProvider')]
    public function testReturnsNullForMalformedParams(array $params, string $why): void
    {
        $message = new RequestMessage(
            id: 1,
            method: 'textDocument/hover',
            params: $params,
        );

        self::assertNull(
            TextDocumentPositionParams::tryFromMessage($message),
            $why,
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function malformedParamsProvider(): iterable
    {
        yield 'textDocument not array' => [
            ['textDocument' => 'nope', 'position' => ['line' => 0, 'character' => 0]],
            'A non-array textDocument cannot be parsed',
        ];
        yield 'uri not string' => [
            ['textDocument' => ['uri' => 42], 'position' => ['line' => 0, 'character' => 0]],
            'A non-string uri cannot be parsed',
        ];
        yield 'position not array' => [
            ['textDocument' => ['uri' => 'file:///foo.php'], 'position' => 'nope'],
            'A non-array position cannot be parsed',
        ];
        yield 'line not int' => [
            ['textDocument' => ['uri' => 'file:///foo.php'], 'position' => ['line' => 1.5, 'character' => 0]],
            'A non-int line cannot be parsed',
        ];
        yield 'character not int' => [
            ['textDocument' => ['uri' => 'file:///foo.php'], 'position' => ['line' => 0, 'character' => 1.5]],
            'A non-int character cannot be parsed',
        ];
    }
}
