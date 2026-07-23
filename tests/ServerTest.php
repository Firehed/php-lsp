<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\WritableBuffer;
use Firehed\PhpLsp\Capability\CapabilityNegotiator;
use Firehed\PhpLsp\Handler\HandlerInterface;
use Firehed\PhpLsp\Handler\LifecycleHandler;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\ResponseError;
use Firehed\PhpLsp\Server;
use Firehed\PhpLsp\ServerInfo;
use Firehed\PhpLsp\Transport\EndOfStream;
use Firehed\PhpLsp\Transport\MalformedFrame;
use Firehed\PhpLsp\Transport\MessageReader;
use Firehed\PhpLsp\Transport\MessageWriter;
use Firehed\PhpLsp\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Server::class)]
class ServerTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testFullLifecycle(): void
    {
        $input = $this->buildMessages(
            $this->initializeJson(1),
            $this->initializedJson(),
            $this->requestJson(2, 'shutdown'),
            $this->notificationJson('exit'),
        );
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'));

        $exitCode = $server->run();

        self::assertSame(0, $exitCode);

        $responses = $this->decodeResponses($outputBuffer->buffer());

        $initialize = $this->responseWithId($responses, 1);
        $result = $initialize['result'];
        assert(is_array($result));
        self::assertArrayHasKey('capabilities', $result, 'initialize advertises capabilities');
        self::assertArrayHasKey('serverInfo', $result, 'initialize reports server info');

        $shutdown = $this->responseWithId($responses, 2);
        self::assertArrayHasKey('result', $shutdown, 'shutdown is answered with a success result');
        self::assertNull($shutdown['result'], 'shutdown result is null');
    }

    public function testUnknownMethodReturnsError(): void
    {
        $input = $this->buildMessages(
            $this->initializeJson(100),
            $this->initializedJson(),
            $this->requestJson(1, 'unknown/method'),
            $this->requestJson(2, 'shutdown'),
            $this->notificationJson('exit'),
        );
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'));

        $server->run();

        $responses = $this->decodeResponses($outputBuffer->buffer());

        self::assertSame(
            ResponseError::methodNotFound()->code,
            $this->errorCode($this->responseWithId($responses, 1)),
            'an unknown method gets MethodNotFound',
        );
    }

    public function testRequestBeforeInitializeIsRejected(): void
    {
        $input = $this->buildMessages(
            $this->requestJson(5, 'textDocument/hover'),
            $this->notificationJson('exit'),
        );
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'));

        $server->run();

        $responses = $this->decodeResponses($outputBuffer->buffer());

        self::assertSame(
            ResponseError::serverNotInitialized()->code,
            $this->errorCode($this->responseWithId($responses, 5)),
            'a request before initialize gets ServerNotInitialized (RFC 1 §4.8)',
        );
    }

    public function testRequestAfterShutdownIsRejected(): void
    {
        $input = $this->buildMessages(
            $this->initializeJson(100),
            $this->initializedJson(),
            $this->requestJson(2, 'shutdown'),
            $this->requestJson(5, 'textDocument/hover'),
            $this->notificationJson('exit'),
        );
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'));

        $exitCode = $server->run();

        self::assertSame(0, $exitCode, 'a clean shutdown then exit still exits with 0');

        $responses = $this->decodeResponses($outputBuffer->buffer());

        self::assertSame(
            ResponseError::invalidRequest()->code,
            $this->errorCode($this->responseWithId($responses, 5)),
            'a request after shutdown gets InvalidRequest (RFC 1 §4.8)',
        );
    }

    /**
     * A gated message must be answered with the lifecycle error *and* never
     * reach a handler (RFC 1 §4.8; LSP "Server lifecycle" requires pre-
     * `initialize` notifications be dropped). Asserting the response code alone
     * cannot tell enforcement from theatre: a server that dispatches the
     * message and then overwrites the result with the gate error emits byte-
     * identical output, while having mutated DocumentManager and SymbolIndex.
     *
     * @param list<string> $preamble
     */
    #[DataProvider('gatedMessages')]
    public function testGatedMessageIsNeverDispatched(
        array $preamble,
        string $gated,
        ?int $expectedErrorCode,
    ): void {
        $spy = new class implements HandlerInterface {
            /** @var list<string> */
            public array $dispatched = [];

            public function supports(string $method): bool
            {
                return $method === 'test/spy';
            }

            public function handle(Message $message): mixed
            {
                $this->dispatched[] = $message->method;
                return null;
            }
        };

        $input = $this->buildMessages(...[...$preamble, $gated, $this->notificationJson('exit')]);
        $outputBuffer = new WritableBuffer();

        $lifecycle = new LifecycleHandler(new CapabilityNegotiator(new ServerInfo('test', '1.0')));
        $transport = $this->createTransport($input, $outputBuffer);
        $server = new Server($transport, $lifecycle, [$spy], new ParserService());

        $server->run();

        self::assertSame([], $spy->dispatched, 'a gated message must never reach a handler');

        $responses = $this->decodeResponses($outputBuffer->buffer());
        $answers = array_values(array_filter(
            $responses,
            fn (array $response): bool => ($response['id'] ?? null) === 99,
        ));

        if ($expectedErrorCode === null) {
            self::assertSame([], $answers, 'a gated notification is dropped, not answered');
            self::assertCount(
                $this->countRequests($preamble),
                $responses,
                'a gated notification produces no response frame of any id',
            );
            return;
        }

        self::assertCount(1, $answers, 'a gated request is answered exactly once');
        self::assertSame(
            $expectedErrorCode,
            $this->errorCode($answers[0]),
            'the gated request carries the lifecycle error for the current state',
        );
    }

    /**
     * The preamble carries the requests that produce a response frame, so the
     * notification cases can assert on the total frame count.
     *
     * @return iterable<string, array{list<string>, string, ?int}>
     *
     * @codeCoverageIgnore
     */
    public static function gatedMessages(): iterable
    {
        $request = json_encode(
            ['jsonrpc' => '2.0', 'id' => 99, 'method' => 'test/spy'],
            JSON_THROW_ON_ERROR,
        );
        $notification = json_encode(
            ['jsonrpc' => '2.0', 'method' => 'test/spy'],
            JSON_THROW_ON_ERROR,
        );
        $initialize = json_encode(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]],
            JSON_THROW_ON_ERROR,
        );
        $initialized = json_encode(['jsonrpc' => '2.0', 'method' => 'initialized'], JSON_THROW_ON_ERROR);
        $shutdown = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'], JSON_THROW_ON_ERROR);

        // Only the id-bearing preamble messages produce response frames, so the
        // expected frame count for the notification cases is the request count.
        yield 'request before initialize' => [[], $request, -32002];
        yield 'notification before initialize' => [[], $notification, null];
        yield 'request after shutdown' => [[$initialize, $initialized, $shutdown], $request, -32600];
        yield 'notification after shutdown' => [[$initialize, $initialized, $shutdown], $notification, null];
    }

    /**
     * Parse dedup is scoped to one handled LSP message, notifications included
     * (0002-execution-plan.md, Section 8.5). Each of the three sync messages
     * below costs exactly one parse: not the two the sync path used to spend on
     * every keystroke, and not one in total, which is the standing cache the
     * Step 0 spike declined to add.
     */
    public function testParsesAreScopedToOneMessage(): void
    {
        $uri = 'file:///fixtures/src/Domain/User.php';
        $text = $this->loadFixture('src/Domain/User.php');

        $didOpen = $this->notificationJson('textDocument/didOpen', [
            'textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text],
        ]);
        // Re-sending identical text is what separates a message-scoped memo from
        // a standing one: the memo must have been discarded, so this parses again.
        $didChange = $this->notificationJson('textDocument/didChange', [
            'textDocument' => ['uri' => $uri, 'version' => 2],
            'contentChanges' => [['text' => $text]],
        ]);

        $input = $this->buildMessages(
            $this->initializeJson(),
            $this->initializedJson(),
            $didOpen,
            $didChange,
            $didChange,
            $this->notificationJson('exit'),
        );
        $outputBuffer = new WritableBuffer();

        $parser = new ParserService();
        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'), __DIR__ . '/Fixtures', $parser);

        $server->run();

        self::assertSame(
            3,
            $parser->getMetrics()->getParseCount(),
            'three sync messages, one parse each',
        );
    }

    /**
     * The same boundary, exercised by a *request* rather than a notification.
     *
     * The sibling test above drives notifications only, so it cannot tell
     * whether the discard runs for requests: guarding the discard on
     * `!$message instanceof RequestMessage` leaves it green. Two identical
     * completion requests separate the cases — the second re-parses only if the
     * first message's memo was discarded.
     */
    public function testParsesAreScopedToOneMessageOnTheRequestPath(): void
    {
        $fixture = 'src/Completion/Variables.php';
        $uri = 'file:///fixtures/' . $fixture;
        $text = $this->loadFixture($fixture);
        $cursor = $this->locateCursor($text, 'param_prefix');

        $didOpen = $this->notificationJson('textDocument/didOpen', [
            'textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text],
        ]);
        $completion = $this->requestJson(1, 'textDocument/completion', [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $cursor['line'], 'character' => $cursor['character']],
        ]);

        $input = $this->buildMessages(
            $this->initializeJson(),
            $this->initializedJson(),
            $didOpen,
            $completion,
            $completion,
            $this->notificationJson('exit'),
        );
        $outputBuffer = new WritableBuffer();

        $parser = new ParserService();
        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'), __DIR__ . '/Fixtures', $parser);

        $server->run();

        self::assertSame(
            3,
            $parser->getMetrics()->getParseCount(),
            'one didOpen and two completion requests, one parse each',
        );
    }

    /**
     * A handler failure must be answered with InternalError rather than taking
     * down the read loop (RFC 1 §9): an editor session that dies on one failed
     * request loses all unsaved server state. The later shutdown proves the loop
     * survived, not merely that one response was written.
     */
    public function testHandlerFailureYieldsInternalErrorAndKeepsRunning(): void
    {
        $throwing = new class implements HandlerInterface {
            public function supports(string $method): bool
            {
                return $method === 'test/throw';
            }

            public function handle(Message $message): mixed
            {
                throw new \RuntimeException('boom');
            }
        };

        $input = $this->buildMessages(
            $this->initializeJson(100),
            $this->initializedJson(),
            $this->requestJson(7, 'test/throw'),
            $this->requestJson(8, 'shutdown'),
            $this->notificationJson('exit'),
        );
        $outputBuffer = new WritableBuffer();

        $lifecycle = new LifecycleHandler(new CapabilityNegotiator(new ServerInfo('test', '1.0')));
        $transport = $this->createTransport($input, $outputBuffer);
        $server = new Server($transport, $lifecycle, [$throwing], new ParserService());

        $exitCode = $server->run();

        self::assertSame(0, $exitCode, 'a handler failure does not terminate the read loop');

        $responses = $this->decodeResponses($outputBuffer->buffer());

        self::assertSame(
            ResponseError::internalError()->code,
            $this->errorCode($this->responseWithId($responses, 7)),
            'a throwing handler yields InternalError (RFC 1 §9)',
        );
        self::assertArrayHasKey(
            'result',
            $this->responseWithId($responses, 8),
            'the loop survives the failure and still answers later requests',
        );
    }

    /**
     * The §8.1 mechanism for RFC 1 §9: a malformed frame is answered with an
     * error response and the read loop keeps going. The id-less ParseError is
     * JSON-RPC's answer when the id cannot be recovered from the bad frame.
     */
    public function testMalformedFrameIsAnsweredAndDoesNotStopTheLoop(): void
    {
        $garbage = 'this is not json';
        $input = $this->buildMessages($this->initializeJson(100), $this->initializedJson())
            . 'Content-Length: ' . strlen($garbage) . "\r\n\r\n" . $garbage
            . $this->buildMessages(
                $this->requestJson(8, 'shutdown'),
                $this->notificationJson('exit'),
            );
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'));

        $exitCode = $server->run();

        self::assertSame(0, $exitCode, 'a malformed frame does not terminate the process');

        $responses = $this->decodeResponses($outputBuffer->buffer());

        $parseErrors = array_filter(
            $responses,
            fn (array $response): bool => ($response['id'] ?? null) === null
                && $this->errorCode($response) === ResponseError::parseError()->code,
        );
        self::assertCount(1, $parseErrors, 'the malformed frame is answered with a null-id ParseError');

        self::assertArrayHasKey(
            'result',
            $this->responseWithId($responses, 8),
            'the loop survives the malformed frame and still answers later requests',
        );
    }

    /**
     * A client that disconnects without sending `exit` ends the stream. That is
     * end of stream rather than a malformed frame, so the server closes down
     * quietly and reports the same non-clean exit as an exit without shutdown.
     */
    public function testStreamEndingWithoutExitReturnsOne(): void
    {
        $input = $this->buildMessages($this->initializeJson(1), $this->initializedJson());
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'));

        self::assertSame(1, $server->run(), 'a disconnect without exit is not a clean shutdown');
    }

    public function testExitWithoutShutdownReturnsOne(): void
    {
        $input = $this->buildMessages($this->notificationJson('exit'));
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'));

        $exitCode = $server->run();

        self::assertSame(1, $exitCode);
    }

    /**
     * Only id-bearing messages are answered, so this is how many response
     * frames a preamble accounts for.
     *
     * @param list<string> $jsonMessages
     */
    private function countRequests(array $jsonMessages): int
    {
        $count = 0;
        foreach ($jsonMessages as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decoded) && array_key_exists('id', $decoded)) {
                $count++;
            }
        }

        return $count;
    }

    private function initializeJson(int $id = 1): string
    {
        return $this->requestJson($id, 'initialize', ['processId' => 1234, 'capabilities' => []]);
    }

    private function initializedJson(): string
    {
        return $this->notificationJson('initialized');
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function requestJson(int $id, string $method, ?array $params = null): string
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $message['params'] = $params;
        }
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function notificationJson(string $method, ?array $params = null): string
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method];
        if ($params !== null) {
            $message['params'] = $params;
        }
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    private function buildMessages(string ...$jsonMessages): string
    {
        $result = '';
        foreach ($jsonMessages as $json) {
            $result .= "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
        }
        return $result;
    }

    /**
     * Read the framed JSON-RPC responses the server wrote back, the way a client
     * would, so assertions inspect decoded structure rather than raw substrings.
     *
     * @return list<array<array-key, mixed>>
     */
    private function decodeResponses(string $output): array
    {
        $prefix = 'Content-Length: ';
        $responses = [];
        $offset = 0;

        while (($headerEnd = strpos($output, "\r\n\r\n", $offset)) !== false) {
            $header = substr($output, $offset, $headerEnd - $offset);
            self::assertStringStartsWith($prefix, $header, 'each frame is Content-Length framed');
            $length = (int) substr($header, strlen($prefix));

            $bodyStart = $headerEnd + 4;
            $decoded = json_decode(substr($output, $bodyStart, $length), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, 'each frame body is a JSON object');
            $responses[] = $decoded;

            $offset = $bodyStart + $length;
        }

        return $responses;
    }

    /**
     * @param list<array<array-key, mixed>> $responses
     * @return array<array-key, mixed>
     */
    private function responseWithId(array $responses, int $id): array
    {
        foreach ($responses as $response) {
            if (($response['id'] ?? null) === $id) {
                return $response;
            }
        }

        self::fail("no response found with id $id");
    }

    /**
     * @param array<array-key, mixed> $response
     */
    private function errorCode(array $response): int
    {
        $error = $response['error'] ?? null;
        self::assertIsArray($error, 'the response carries an error object');
        $code = $error['code'] ?? null;
        self::assertIsInt($code, 'the error carries an integer code');

        return $code;
    }

    private function createTransport(string $input, WritableBuffer $outputBuffer): TransportInterface
    {
        $inputBuffer = new ReadableBuffer($input);
        $reader = new MessageReader($inputBuffer);
        $writer = new MessageWriter($outputBuffer);

        return new class ($reader, $writer, $outputBuffer) implements TransportInterface {
            public function __construct(
                private MessageReader $reader,
                private MessageWriter $writer,
                private WritableBuffer $outputBuffer,
            ) {
            }

            public function read(): Message|MalformedFrame|EndOfStream
            {
                return $this->reader->read();
            }

            public function write(\Firehed\PhpLsp\Protocol\ResponseMessage $response): void
            {
                $this->writer->write($response);
            }

            public function close(): void
            {
                $this->outputBuffer->close();
            }
        };
    }
}
