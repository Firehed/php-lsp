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
        $initializeJson = '{"jsonrpc":"2.0","id":1,"method":"initialize",'
            . '"params":{"processId":1234,"capabilities":{}}}';
        $initializedJson = '{"jsonrpc":"2.0","method":"initialized"}';
        $shutdownJson = '{"jsonrpc":"2.0","id":2,"method":"shutdown"}';
        $exitJson = '{"jsonrpc":"2.0","method":"exit"}';

        $input = $this->buildMessages($initializeJson, $initializedJson, $shutdownJson, $exitJson);
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = new Server($transport, new ServerInfo('test', '1.0'));

        $exitCode = $server->run();

        self::assertSame(0, $exitCode);

        $output = $outputBuffer->buffer();

        // Verify initialize response
        self::assertStringContainsString('"jsonrpc":"2.0"', $output);
        self::assertStringContainsString('"id":1', $output);
        self::assertStringContainsString('"capabilities"', $output);
        self::assertStringContainsString('"serverInfo"', $output);

        // Verify shutdown response
        self::assertStringContainsString('"id":2', $output);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $unknownJson = '{"jsonrpc":"2.0","id":1,"method":"unknown/method"}';
        $shutdownJson = '{"jsonrpc":"2.0","id":2,"method":"shutdown"}';
        $exitJson = '{"jsonrpc":"2.0","method":"exit"}';

        $input = $this->buildMessages($unknownJson, $shutdownJson, $exitJson);
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = new Server($transport, new ServerInfo('test', '1.0'));

        $server->run();

        $output = $outputBuffer->buffer();

        // Should contain method not found error
        self::assertStringContainsString('"error"', $output);
        self::assertStringContainsString((string) ResponseError::methodNotFound()->code, $output);
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

        $didOpenJson = (string) json_encode([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text],
            ],
        ], JSON_THROW_ON_ERROR);
        // Re-sending identical text is what separates a message-scoped memo from
        // a standing one: the memo must have been discarded, so this parses again.
        $didChangeJson = (string) json_encode([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => $uri, 'version' => 2],
                'contentChanges' => [['text' => $text]],
            ],
        ], JSON_THROW_ON_ERROR);
        $exitJson = '{"jsonrpc":"2.0","method":"exit"}';

        $input = $this->buildMessages($didOpenJson, $didChangeJson, $didChangeJson, $exitJson);
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

    public function testExitWithoutShutdownReturnsOne(): void
    {
        $exitJson = '{"jsonrpc":"2.0","method":"exit"}';

        $input = $this->buildMessages($exitJson);
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = new Server($transport, new ServerInfo('test', '1.0'));

        $exitCode = $server->run();

        self::assertSame(1, $exitCode);
    }

    private function buildMessages(string ...$jsonMessages): string
    {
        $result = '';
        foreach ($jsonMessages as $json) {
            $result .= "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
        }
        return $result;
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
