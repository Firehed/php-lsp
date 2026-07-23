<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Transport;

use Amp\ByteStream\ReadableBuffer;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Protocol\ResponseError;
use Firehed\PhpLsp\Transport\EndOfStream;
use Firehed\PhpLsp\Transport\MalformedFrame;
use Firehed\PhpLsp\Transport\MessageReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testReadReturnsEndOfStreamOnEof(): void
    {
        $buffer = new ReadableBuffer('');
        $reader = new MessageReader($buffer);

        self::assertInstanceOf(
            EndOfStream::class,
            $reader->read(),
            'a cleanly closed stream is end of stream, not a malformed frame',
        );
    }

    /**
     * RFC 1 §9: "A message lacking a required header MUST be distinguishable
     * from end of stream." Both used to surface as null, so a truncated or
     * unframed write looked exactly like the client hanging up.
     */
    public function testFrameWithoutContentLengthIsNotEndOfStream(): void
    {
        $reader = new MessageReader(new ReadableBuffer("Content-Type: application/json\r\n\r\n"));

        $result = $reader->read();

        self::assertInstanceOf(
            MalformedFrame::class,
            $result,
            'a header block carrying no Content-Length is a malformed frame',
        );
        self::assertSame(ResponseError::parseError()->code, $result->error->code);
    }

    public function testUnterminatedHeaderIsNotEndOfStream(): void
    {
        $reader = new MessageReader(new ReadableBuffer('Content-Length: 5'));

        self::assertInstanceOf(
            MalformedFrame::class,
            $reader->read(),
            'bytes that never terminate the header block are malformed, not EOF',
        );
    }

    public function testBodyTruncatedByEndOfStreamIsMalformed(): void
    {
        $reader = new MessageReader(new ReadableBuffer("Content-Length: 100\r\n\r\n{\"a\":1}"));

        self::assertInstanceOf(
            MalformedFrame::class,
            $reader->read(),
            'a body shorter than its declared Content-Length is a malformed frame',
        );
    }

    public function testUnparseableBodyYieldsParseError(): void
    {
        $reader = new MessageReader(new ReadableBuffer($this->frame('this is not json')));

        $result = $reader->read();

        self::assertInstanceOf(MalformedFrame::class, $result);
        self::assertSame(
            ResponseError::parseError()->code,
            $result->error->code,
            'unparseable JSON yields ParseError (RFC 1 §9)',
        );
    }

    /**
     * Valid JSON that is not a usable JSON-RPC message. These used to trip an
     * assert() inside the message factories, terminating the process on input a
     * client controls.
     */
    #[DataProvider('structurallyInvalidBodies')]
    public function testStructurallyInvalidMessageYieldsInvalidRequest(string $body): void
    {
        $reader = new MessageReader(new ReadableBuffer($this->frame($body)));

        $result = $reader->read();

        self::assertInstanceOf(MalformedFrame::class, $result, "should reject: $body");
        self::assertSame(
            ResponseError::invalidRequest()->code,
            $result->error->code,
            "valid JSON that is not a JSON-RPC message yields InvalidRequest: $body",
        );
    }

    /**
     * @return iterable<string, array{string}>
     *
     * @codeCoverageIgnore
     */
    public static function structurallyInvalidBodies(): iterable
    {
        yield 'not an object' => ['"just a string"'];
        yield 'no method' => ['{"jsonrpc":"2.0","id":1}'];
        yield 'non-string method' => ['{"jsonrpc":"2.0","method":42}'];
        yield 'non-array params' => ['{"jsonrpc":"2.0","method":"x","params":"nope"}'];
        yield 'non-scalar id' => ['{"jsonrpc":"2.0","id":true,"method":"x"}'];
    }

    /**
     * The frame is consumed even when it cannot be decoded, so one bad message
     * does not desynchronize the stream: the server answers it with an error and
     * keeps serving (RFC 1 §9).
     */
    public function testReaderRecoversAfterAMalformedFrame(): void
    {
        $input = $this->frame('not json') . $this->frame('{"jsonrpc":"2.0","method":"initialized"}');
        $reader = new MessageReader(new ReadableBuffer($input));

        self::assertInstanceOf(MalformedFrame::class, $reader->read());

        $recovered = $reader->read();

        self::assertInstanceOf(
            NotificationMessage::class,
            $recovered,
            'the reader resumes at the next frame after a malformed one',
        );
        self::assertSame('initialized', $recovered->method);
    }

    private function frame(string $body): string
    {
        return 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
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
