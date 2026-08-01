<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Transport;

use Amp\ByteStream\WritableBuffer;
use Firehed\PhpLsp\Protocol\OutgoingRequest;
use Firehed\PhpLsp\Protocol\ResponseMessage;
use Firehed\PhpLsp\Transport\MessageWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageWriter::class)]
class MessageWriterTest extends TestCase
{
    public function testWriteResponseMessage(): void
    {
        $buffer = new WritableBuffer();
        $writer = new MessageWriter($buffer);

        $response = ResponseMessage::success(1, ['capabilities' => []]);
        $writer->write($response);
        $buffer->close();

        $output = $buffer->buffer();
        $json = '{"jsonrpc":"2.0","id":1,"result":{"capabilities":[]}}';
        $expected = "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;

        self::assertSame($expected, $output);
    }

    public function testWriteServerInitiatedRequest(): void
    {
        $buffer = new WritableBuffer();
        $writer = new MessageWriter($buffer);

        $writer->write(new OutgoingRequest('reg-1', 'client/registerCapability', ['registrations' => []]));
        $buffer->close();

        $separator = strpos($buffer->buffer(), "\r\n\r\n");
        self::assertNotFalse($separator, 'the frame has a header/body separator');
        $header = substr($buffer->buffer(), 0, $separator);
        $body = substr($buffer->buffer(), $separator + 4);

        self::assertSame(
            'Content-Length: ' . strlen($body),
            $header,
            'a server-initiated request frames through the same write path as a response',
        );
        // Compared as decoded structure, so key order and slash-escaping are irrelevant.
        self::assertEquals(
            [
                'jsonrpc' => '2.0',
                'id' => 'reg-1',
                'method' => 'client/registerCapability',
                'params' => ['registrations' => []],
            ],
            json_decode($body, true, flags: JSON_THROW_ON_ERROR),
            'the request serializes to a well-formed JSON-RPC request',
        );
    }

    public function testWriteMultipleMessages(): void
    {
        $buffer = new WritableBuffer();
        $writer = new MessageWriter($buffer);

        $response1 = ResponseMessage::success(1, null);
        $response2 = ResponseMessage::success(2, ['data' => 'value']);

        $writer->write($response1);
        $writer->write($response2);
        $buffer->close();

        $output = $buffer->buffer();

        $json1 = '{"jsonrpc":"2.0","id":1,"result":null}';
        $json2 = '{"jsonrpc":"2.0","id":2,"result":{"data":"value"}}';

        self::assertStringContainsString("Content-Length: " . strlen($json1), $output);
        self::assertStringContainsString($json1, $output);
        self::assertStringContainsString("Content-Length: " . strlen($json2), $output);
        self::assertStringContainsString($json2, $output);
    }
}
