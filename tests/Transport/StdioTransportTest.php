<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Transport;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\WritableBuffer;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Protocol\ResponseMessage;
use Firehed\PhpLsp\Transport\EndOfStream;
use Firehed\PhpLsp\Transport\StdioTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StdioTransport::class)]
class StdioTransportTest extends TestCase
{
    public function testReadsAFramedMessageFromTheInputStream(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"initialize"}';
        $transport = new StdioTransport(
            new ReadableBuffer('Content-Length: ' . strlen($json) . "\r\n\r\n" . $json),
            new WritableBuffer(),
        );

        $message = $transport->read();

        self::assertInstanceOf(RequestMessage::class, $message, 'a framed request is decoded');
        self::assertSame('initialize', $message->method);
    }

    public function testReportsEndOfStreamOnAClosedInput(): void
    {
        $transport = new StdioTransport(new ReadableBuffer(''), new WritableBuffer());

        self::assertInstanceOf(
            EndOfStream::class,
            $transport->read(),
            'the read outcome is passed through from the reader',
        );
    }

    public function testWritesAFramedResponseToTheOutputStream(): void
    {
        $output = new WritableBuffer();
        $transport = new StdioTransport(new ReadableBuffer(''), $output);

        $transport->write(ResponseMessage::success(1, null));
        $output->close();

        self::assertSame(
            "Content-Length: 38\r\n\r\n" . '{"jsonrpc":"2.0","id":1,"result":null}',
            $output->buffer(),
            'the response is Content-Length framed on the output stream',
        );
    }

    public function testCloseClosesBothStreams(): void
    {
        $input = new ReadableBuffer('');
        $output = new WritableBuffer();
        $transport = new StdioTransport($input, $output);

        $transport->close();

        self::assertTrue($input->isClosed(), 'the input stream is closed');
        self::assertTrue($output->isClosed(), 'the output stream is closed');
    }
}
