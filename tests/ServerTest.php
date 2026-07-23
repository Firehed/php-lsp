<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\WritableBuffer;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\ResponseError;
use Firehed\PhpLsp\Server;
use Firehed\PhpLsp\ServerInfo;
use Firehed\PhpLsp\Transport\MessageReader;
use Firehed\PhpLsp\Transport\MessageWriter;
use Firehed\PhpLsp\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
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
        $server = new Server($transport, new ServerInfo('test', '1.0'));

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
        $server = new Server($transport, new ServerInfo('test', '1.0'));

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
        $server = new Server($transport, new ServerInfo('test', '1.0'));

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
        $server = new Server($transport, new ServerInfo('test', '1.0'));

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
        $server = new Server($transport, new ServerInfo('test', '1.0'), __DIR__ . '/Fixtures', $parser);

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
        $server = new Server($transport, new ServerInfo('test', '1.0'), __DIR__ . '/Fixtures', $parser);

        $server->run();

        self::assertSame(
            3,
            $parser->getMetrics()->getParseCount(),
            'one didOpen and two completion requests, one parse each',
        );
    }

    public function testExitWithoutShutdownReturnsOne(): void
    {
        $input = $this->buildMessages($this->notificationJson('exit'));
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = new Server($transport, new ServerInfo('test', '1.0'));

        $exitCode = $server->run();

        self::assertSame(1, $exitCode);
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

            public function read(): ?\Firehed\PhpLsp\Protocol\Message
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
