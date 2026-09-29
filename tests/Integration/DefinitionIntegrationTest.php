<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\WritableBuffer;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\OutgoingMessageInterface;
use Firehed\PhpLsp\Protocol\ServerInfo;
use Firehed\PhpLsp\Server;
use Firehed\PhpLsp\Tests\BuildsContainerTrait;
use Firehed\PhpLsp\Transport\EndOfStream;
use Firehed\PhpLsp\Transport\MalformedFrame;
use Firehed\PhpLsp\Transport\MessageReader;
use Firehed\PhpLsp\Transport\MessageWriter;
use Firehed\PhpLsp\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Server::class)]
class DefinitionIntegrationTest extends TestCase
{
    use BuildsContainerTrait;

    public function testGoToDefinitionEndToEnd(): void
    {
        // Simulate full LSP interaction:
        // 1. Initialize
        // 2. Open file with class definition
        // 3. Open file with class usage
        // 4. Request definition
        // 5. Shutdown + exit

        $messages = [
            // Initialize
            $this->makeRequest(1, 'initialize', [
                'processId' => getmypid(),
                'capabilities' => [],
                'rootUri' => 'file:///project',
            ]),
            // Initialized notification
            $this->makeNotification('initialized', []),
            // Open class definition file
            $this->makeNotification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///project/src/MyClass.php',
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => '<?php class MyClass { public function hello() {} }',
                ],
            ]),
            // Open usage file
            $this->makeNotification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///project/src/usage.php',
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => '<?php $x = new MyClass();',
                ],
            ]),
            // Request definition at "MyClass" in usage.php
            $this->makeRequest(2, 'textDocument/definition', [
                'textDocument' => ['uri' => 'file:///project/src/usage.php'],
                'position' => ['line' => 0, 'character' => 15], // On "MyClass"
            ]),
            // Shutdown
            $this->makeRequest(3, 'shutdown', null),
            // Exit
            $this->makeNotification('exit', null),
        ];

        $input = implode('', array_map(fn($m) => $this->encode($m), $messages));
        $outputBuffer = new WritableBuffer();

        $transport = $this->createTransport($input, $outputBuffer);
        $server = Server::forProject($transport, new ServerInfo('test', '1.0'), $this->buildContainer());

        $exitCode = $server->run();

        self::assertSame(0, $exitCode);

        $output = $outputBuffer->buffer();

        // Parse responses
        self::assertStringContainsString('"id":2', $output, 'Should have response to definition request');
        // JSON escapes / as \/ so check for that
        self::assertStringContainsString('MyClass.php', $output, 'Definition should point to MyClass.php');
    }

    public function testADocumentThatWasNeverOpenedIsAnsweredFromDisk(): void
    {
        $root = dirname(__DIR__) . '/Fixtures';
        $path = $root . '/src/Domain/User.php';
        $lines = explode("\n", (string) file_get_contents($path));
        $declaration = array_find_key(
            $lines,
            static fn(string $line): bool => str_contains($line, 'implements Entity'),
        );
        self::assertIsInt($declaration, 'the fixture must still implement Entity for this test to mean anything');

        $messages = [
            $this->makeRequest(1, 'initialize', [
                'processId' => getmypid(),
                'capabilities' => [],
                'rootUri' => FileUri::fromPath($root),
            ]),
            $this->makeNotification('initialized', []),
            $this->makeRequest(2, 'textDocument/definition', [
                'textDocument' => ['uri' => FileUri::fromPath($path)],
                'position' => [
                    'line' => $declaration,
                    'character' => (int) strpos($lines[$declaration], 'Entity') + 1,
                ],
            ]),
            $this->makeRequest(3, 'shutdown', null),
            $this->makeNotification('exit', null),
        ];

        $outputBuffer = new WritableBuffer();
        $server = Server::forProject(
            $this->createTransport(implode('', array_map($this->encode(...), $messages)), $outputBuffer),
            new ServerInfo('test', '1.0'),
            $this->buildContainer(),
            $root,
        );

        self::assertSame(0, $server->run(), 'the session must end cleanly');
        self::assertStringContainsString(
            'Entity.php',
            $outputBuffer->buffer(),
            'a server answers for a document whether or not it is open ([LSP] textDocument/didOpen)',
        );
    }

    /**
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    private function makeRequest(int $id, string $method, ?array $params): array
    {
        $msg = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ];
        if ($params !== null) {
            $msg['params'] = $params;
        }
        return $msg;
    }

    /**
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    private function makeNotification(string $method, ?array $params): array
    {
        $msg = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        if ($params !== null) {
            $msg['params'] = $params;
        }
        return $msg;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function encode(array $message): string
    {
        $json = json_encode($message, JSON_THROW_ON_ERROR);
        return "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
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

            public function write(OutgoingMessageInterface $message): void
            {
                $this->writer->write($message);
            }

            public function close(): void
            {
                $this->outputBuffer->close();
            }
        };
    }
}
