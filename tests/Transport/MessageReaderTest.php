<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Transport;

use Amp\ByteStream\ReadableBuffer;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Transport\MessageReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageReader::class)]
class MessageReaderTest extends TestCase
{
    public function testReadRequest(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"processId":1234}}';
        $message = "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;

        $buffer = new ReadableBuffer($message);
        $reader = new MessageReader($buffer);

        $request = $reader->read();

        self::assertInstanceOf(RequestMessage::class, $request);
        self::assertSame(1, $request->id);
        self::assertSame('initialize', $request->method);
        self::assertSame(['processId' => 1234], $request->params);
    }

    public function testReadNotification(): void
    {
        $json = '{"jsonrpc":"2.0","method":"initialized","params":{}}';
        $message = "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;

        $buffer = new ReadableBuffer($message);
        $reader = new MessageReader($buffer);

        $notification = $reader->read();

        self::assertInstanceOf(NotificationMessage::class, $notification);
        self::assertSame('initialized', $notification->method);
    }

    public function testReadMultipleMessages(): void
    {
        $json1 = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}';
        $json2 = '{"jsonrpc":"2.0","method":"initialized"}';

        $message = "Content-Length: " . strlen($json1) . "\r\n\r\n" . $json1;
        $message .= "Content-Length: " . strlen($json2) . "\r\n\r\n" . $json2;

        $buffer = new ReadableBuffer($message);
        $reader = new MessageReader($buffer);

        $request = $reader->read();
        $notification = $reader->read();

        self::assertInstanceOf(RequestMessage::class, $request);
        self::assertInstanceOf(NotificationMessage::class, $notification);
    }

    public function testReadReturnsNullOnEof(): void
    {
        $buffer = new ReadableBuffer('');
        $reader = new MessageReader($buffer);

        $result = $reader->read();

        self::assertNull($result);
    }

    public function testReadWithContentTypeHeader(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"test"}';
        $message = "Content-Length: " . strlen($json) . "\r\n";
        $message .= "Content-Type: application/vscode-jsonrpc; charset=utf-8\r\n";
        $message .= "\r\n" . $json;

        $buffer = new ReadableBuffer($message);
        $reader = new MessageReader($buffer);

        $request = $reader->read();

        self::assertInstanceOf(RequestMessage::class, $request);
        self::assertSame('test', $request->method);
    }
}
