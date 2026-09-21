<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\ErrorCode;
use Firehed\PhpLsp\Protocol\ResponseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseError::class)]
#[CoversClass(ErrorCode::class)]
class ResponseErrorTest extends TestCase
{
    public function testMessageDefaultsFromTheErrorCode(): void
    {
        self::assertSame(
            ErrorCode::ParseError->defaultMessage(),
            (new ResponseError(ErrorCode::ParseError))->message,
            'a caller that names only the code gets the spec-defined message for free',
        );
    }

    public function testMessageOverrideWinsOverTheDefault(): void
    {
        self::assertSame(
            'Method not found: textDocument/hover',
            (new ResponseError(ErrorCode::MethodNotFound, 'Method not found: textDocument/hover'))->message,
            'a caller that has more to say than the default may replace the message',
        );
    }

    public function testSerializationOmitsAbsentData(): void
    {
        self::assertSame(
            ['code' => -32603, 'message' => 'Internal error'],
            (new ResponseError(ErrorCode::InternalError))->jsonSerialize(),
            'an error carrying no data omits the key entirely (JSON-RPC 2.0 § 5.1)',
        );
    }

    public function testSerializationIncludesData(): void
    {
        self::assertSame(
            ['code' => -32603, 'message' => 'Internal error', 'data' => 'boom'],
            (new ResponseError(ErrorCode::InternalError, data: 'boom'))->jsonSerialize(),
            'diagnostic detail is carried in the data member (JSON-RPC 2.0 § 5.1)',
        );
    }

    public function testSerializationEmitsTheIntegerCode(): void
    {
        $encoded = (new ResponseError(ErrorCode::ParseError))->jsonSerialize();

        self::assertSame(-32700, $encoded['code'], 'the wire form is the integer, not the enum case name');
    }
}
