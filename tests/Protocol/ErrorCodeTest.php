<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The integer values are named by JSON-RPC 2.0 § 5.1 and, for
 * `ServerNotInitialized`, by the LSP "Server lifecycle" section. They travel on
 * the wire, so any drift here breaks conforming clients. The literals are
 * repeated by hand so a typo in the enum cannot agree with itself.
 */
#[CoversClass(ErrorCode::class)]
class ErrorCodeTest extends TestCase
{
    #[DataProvider('specifiedCodes')]
    public function testCaseCarriesTheSpecifiedInteger(ErrorCode $case, int $expected): void
    {
        self::assertSame($expected, $case->value, 'the enum value is fixed by the JSON-RPC/LSP wire contract');
    }

    #[DataProvider('defaultMessages')]
    public function testDefaultMessageNamesTheError(ErrorCode $case, string $expected): void
    {
        self::assertSame($expected, $case->defaultMessage(), 'the message JSON-RPC 2.0 § 5.1 gives the code');
    }

    /**
     * @return iterable<string, array{ErrorCode, string}>
     *
     * @codeCoverageIgnore
     */
    public static function defaultMessages(): iterable
    {
        yield 'ParseError' => [ErrorCode::ParseError, 'Parse error'];
        yield 'InvalidRequest' => [ErrorCode::InvalidRequest, 'Invalid Request'];
        yield 'MethodNotFound' => [ErrorCode::MethodNotFound, 'Method not found'];
        yield 'InvalidParams' => [ErrorCode::InvalidParams, 'Invalid params'];
        yield 'InternalError' => [ErrorCode::InternalError, 'Internal error'];
        yield 'ServerNotInitialized' => [ErrorCode::ServerNotInitialized, 'Server not initialized'];
    }

    /**
     * @return iterable<string, array{ErrorCode, int}>
     *
     * @codeCoverageIgnore
     */
    public static function specifiedCodes(): iterable
    {
        yield 'ParseError' => [ErrorCode::ParseError, -32700];
        yield 'InvalidRequest' => [ErrorCode::InvalidRequest, -32600];
        yield 'MethodNotFound' => [ErrorCode::MethodNotFound, -32601];
        yield 'InvalidParams' => [ErrorCode::InvalidParams, -32602];
        yield 'InternalError' => [ErrorCode::InternalError, -32603];
        yield 'ServerNotInitialized' => [ErrorCode::ServerNotInitialized, -32002];
    }
}
