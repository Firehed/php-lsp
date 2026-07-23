<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\ResponseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseError::class)]
class ResponseErrorTest extends TestCase
{
    /**
     * The codes are fixed by JSON-RPC 2.0 and, for ServerNotInitialized, by
     * [LSP] "Server lifecycle". They are asserted as literals rather than
     * against the factories themselves so a typo cannot agree with itself.
     *
     * @param callable(): ResponseError $factory
     */
    #[DataProvider('errorFactories')]
    public function testFactoryUsesTheSpecifiedCode(int $expected, callable $factory): void
    {
        self::assertSame($expected, $factory()->code, 'the factory must emit the specified error code');
    }

    /**
     * Factories are passed as first-class callables rather than invoked here:
     * a data provider runs before coverage tracking starts.
     *
     * @return iterable<string, array{int, callable(): ResponseError}>
     *
     * @codeCoverageIgnore
     */
    public static function errorFactories(): iterable
    {
        yield 'parse error' => [-32700, ResponseError::parseError(...)];
        yield 'invalid request' => [-32600, ResponseError::invalidRequest(...)];
        yield 'method not found' => [-32601, ResponseError::methodNotFound(...)];
        yield 'invalid params' => [-32602, ResponseError::invalidParams(...)];
        yield 'internal error' => [-32603, ResponseError::internalError(...)];
        yield 'server not initialized' => [-32002, ResponseError::serverNotInitialized(...)];
    }

    public function testMethodNotFoundNamesTheMissingMethod(): void
    {
        self::assertStringContainsString(
            'textDocument/hover',
            ResponseError::methodNotFound('textDocument/hover')->message,
            'the unsupported method is reported back to aid debugging',
        );
    }

    public function testSerializationOmitsAbsentData(): void
    {
        self::assertSame(
            ['code' => -32603, 'message' => 'Internal error'],
            ResponseError::internalError()->jsonSerialize(),
            'an error carrying no data omits the key entirely',
        );
    }

    public function testSerializationIncludesData(): void
    {
        self::assertSame(
            ['code' => -32603, 'message' => 'Internal error', 'data' => 'boom'],
            ResponseError::internalError('boom')->jsonSerialize(),
            'diagnostic detail is carried in the data member',
        );
    }
}
