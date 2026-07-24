<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Transport;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
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
#[CoversClass(EndOfStream::class)]
#[CoversClass(MalformedFrame::class)]
class MessageReaderTest extends TestCase
{
    /**
     * A frame does not necessarily arrive in one read: the transport hands over
     * whatever bytes are available, so a body can span several chunks and must
     * be accumulated until Content-Length is satisfied.
     */
    public function testReadsABodyDeliveredAcrossSeveralChunks(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"processId":1234}}';
        $chunks = [
            'Content-Length: ' . strlen($json) . "\r\n\r\n" . substr($json, 0, 10),
            substr($json, 10, 30),
            substr($json, 40),
        ];

        $reader = new MessageReader(new ReadableIterableStream($chunks));

        $request = $reader->read();

        self::assertInstanceOf(RequestMessage::class, $request, 'a split body still yields one message');
        self::assertSame('initialize', $request->method);
        self::assertSame(1, $request->id);
    }

    /**
     * The header block is subject to the same chunking as the body: a pipe can
     * hand over a partial header, so the reader must accumulate until the
     * "\r\n\r\n" terminator arrives rather than judging one chunk.
     */
    public function testReadsAHeaderDeliveredAcrossSeveralChunks(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"initialize"}';
        $chunks = [
            'Content-Len',
            'gth: ' . strlen($json) . "\r\n",
            "\r\n" . $json,
        ];

        $reader = new MessageReader(new ReadableIterableStream($chunks));

        $request = $reader->read();

        self::assertInstanceOf(RequestMessage::class, $request, 'a split header still yields one message');
        self::assertSame('initialize', $request->method);
    }

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

        // The reader must consume the stranded bytes, not just report on them.
        // Server::run answers a malformed frame and continues, so a reader that
        // kept re-reporting the same buffer would spin emitting ParseError
        // frames without end rather than winding the session down.
        self::assertInstanceOf(
            EndOfStream::class,
            $reader->read(),
            'the stranded bytes are consumed, so the next read reports end of stream',
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
        self::assertInstanceOf(
            EndOfStream::class,
            $reader->read(),
            'the partial body is consumed, so one truncated frame is one error',
        );
    }

    /**
     * A partial body left in the buffer would be re-read as the next frame's
     * header block, so bytes the sender chose inside a body could name the
     * Content-Length of the frame after it. One truncated frame must cost one
     * error response, not turn the body into framing.
     */
    public function testTruncatedBodyIsNotReparsedAsHeaders(): void
    {
        $body = "{\"a\":1}\r\n\r\nContent-Length: 9999\r\n\r\n";
        $reader = new MessageReader(new ReadableBuffer("Content-Length: 500\r\n\r\n" . $body));

        self::assertInstanceOf(MalformedFrame::class, $reader->read());
        self::assertInstanceOf(
            EndOfStream::class,
            $reader->read(),
            'header-looking bytes inside a truncated body are not treated as a frame',
        );
    }

    /**
     * Per [LSP] "Base Protocol" the Content-Length value is a byte count, and
     * "the structure of header fields conforms to the HTTP semantic"
     * ([RFC 7230] §3.2) — so §3.3.2's `Content-Length = 1*DIGIT`. A bare (int)
     * cast accepted anything: "abc" became 0, and "-5" became a negative
     * length, which makes substr() consume from the wrong end of the buffer.
     *
     * A client that mis-declares the length is recovered rather than bounced.
     * The content part is JSON, which the decoder can judge on its own, so the
     * remaining bytes are handed to it and a body that parses is served
     * normally.
     */
    #[DataProvider('unusableContentLengths')]
    public function testUnusableContentLengthFallsBackToTheBody(string $value): void
    {
        $input = "Content-Length: $value\r\n\r\n" . '{"jsonrpc":"2.0","method":"initialized"}';
        $reader = new MessageReader(new ReadableBuffer($input));

        $result = $reader->read();

        self::assertInstanceOf(
            NotificationMessage::class,
            $result,
            "a mis-declared Content-Length still delivers a body that parses: $value",
        );
        self::assertSame('initialized', $result->method);
    }

    /**
     * When the remainder is not a JSON message there is nothing to recover, so
     * the frame costs exactly one error and its bytes are consumed. Left in the
     * buffer they would be re-read as the next frame's header block, letting
     * bytes the sender chose inside a body declare the Content-Length of the
     * frame behind them — the framing desync [RFC 7230] §3.3.3 treats as
     * unrecoverable, and a frame-injection primitive besides.
     */
    public function testUnusableContentLengthWithUnrecoverableBodyCostsOneError(): void
    {
        $input = "Content-Length: abc\r\n\r\n"
            . 'not json'
            . $this->frame('{"jsonrpc":"2.0","method":"initialized"}');
        $reader = new MessageReader(new ReadableBuffer($input));

        $result = $reader->read();

        self::assertInstanceOf(MalformedFrame::class, $result, 'an unrecoverable body is a malformed frame');
        self::assertSame(
            ResponseError::parseError()->code,
            $result->error->code,
            'a body that does not parse yields ParseError',
        );
        self::assertInstanceOf(
            EndOfStream::class,
            $reader->read(),
            'the frame consumed its own bytes, so no body is re-read as framing',
        );
    }

    /**
     * @return iterable<string, array{string}>
     *
     * @codeCoverageIgnore
     */
    public static function unusableContentLengths(): iterable
    {
        yield 'non-numeric' => ['abc'];
        yield 'negative' => ['-5'];
        yield 'empty' => [''];
        yield 'fractional' => ['1.5'];
        yield 'scientific notation' => ['1e3'];
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
        yield 'non-array params' => ['{"jsonrpc":"2.0","id":3,"method":"x","params":"nope"}'];
        yield 'non-scalar id' => ['{"jsonrpc":"2.0","id":true,"method":"x"}'];
    }

    /**
     * JSON-RPC 2.0 §5: the response id "MUST be the same as the value of the id
     * member in the Request Object. If there was an error in detecting the id
     * ... it MUST be Null." An id that was detected must therefore be echoed —
     * answering at null instead leaves the client's pending request unresolved
     * forever, because nothing correlates the error back to it.
     *
     * @param int|string|null $expectedId
     */
    #[DataProvider('rejectedFrameIds')]
    public function testRejectedFrameIsAnsweredAtTheRecoverableId(string $body, int|string|null $expectedId): void
    {
        $reader = new MessageReader(new ReadableBuffer($this->frame($body)));

        $result = $reader->read();

        self::assertInstanceOf(MalformedFrame::class, $result, "should reject: $body");
        self::assertSame(
            $expectedId,
            $result->id,
            "the rejected frame is answered at the id it could be correlated to: $body",
        );
    }

    /**
     * @return iterable<string, array{string, int|string|null}>
     *
     * @codeCoverageIgnore
     */
    public static function rejectedFrameIds(): iterable
    {
        yield 'integer id' => ['{"jsonrpc":"2.0","id":7,"method":"x","params":"nope"}', 7];
        yield 'string id' => ['{"jsonrpc":"2.0","id":"abc","method":"x","params":"nope"}', 'abc'];
        yield 'id present, method unusable' => ['{"jsonrpc":"2.0","id":9,"method":42}', 9];
        yield 'id undetectable' => ['{"jsonrpc":"2.0","id":true,"method":"x"}', null];
        yield 'no id at all' => ['{"jsonrpc":"2.0","method":42}', null];
        yield 'unparseable body' => ['not json', null];
    }

    /**
     * JSON-RPC 2.0 §4.1: "The Server MUST NOT reply to a Notification." A frame
     * that is recognisably one — a JSON object naming a method, carrying no id
     * — has no sender to answer, so it is consumed and dropped rather than
     * reported. The frame behind it still reads.
     */
    public function testUnusableNotificationIsDroppedRatherThanAnswered(): void
    {
        $input = $this->frame('{"jsonrpc":"2.0","method":"$/setTrace","params":"verbose"}')
            . $this->frame('{"jsonrpc":"2.0","method":"initialized"}');
        $reader = new MessageReader(new ReadableBuffer($input));

        $result = $reader->read();

        self::assertInstanceOf(
            NotificationMessage::class,
            $result,
            'an unusable notification draws no error outcome, so the next frame surfaces instead',
        );
        self::assertSame('initialized', $result->method);
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
