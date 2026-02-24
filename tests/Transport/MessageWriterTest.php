<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Transport;

use Amp\ByteStream\WritableBuffer;
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
