<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\RequestMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestMessage::class)]
class RequestMessageTest extends TestCase
{
    public function testParseValidRequest(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['processId' => 1234],
        ];

        $request = RequestMessage::fromArray($data);

        self::assertSame(1, $request->id);
        self::assertSame('initialize', $request->method);
        self::assertSame(['processId' => 1234], $request->params);
    }

    public function testParseRequestWithStringId(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 'req-abc',
            'method' => 'textDocument/hover',
            'params' => [],
        ];

        $request = RequestMessage::fromArray($data);

        self::assertSame('req-abc', $request->id);
        self::assertSame('textDocument/hover', $request->method);
    }

    public function testParseRequestWithoutParams(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'shutdown',
        ];

        $request = RequestMessage::fromArray($data);

        self::assertSame(2, $request->id);
        self::assertSame('shutdown', $request->method);
        self::assertNull($request->params);
    }

    public function testParseRequestWithNullParams(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'shutdown',
            'params' => null,
        ];

        $request = RequestMessage::fromArray($data);

        self::assertNull($request->params);
    }

    public function testIsNotification(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ];

        $request = RequestMessage::fromArray($data);

        self::assertFalse($request->isNotification());
    }
}
