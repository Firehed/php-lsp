<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\WritableBuffer;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\ResponseMessage;
use Firehed\PhpLsp\Server;
use Firehed\PhpLsp\ServerInfo;
use Firehed\PhpLsp\Transport\MessageReader;
use Firehed\PhpLsp\Transport\MessageWriter;
use Firehed\PhpLsp\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Server::class)]
class DefinitionIntegrationTest extends TestCase
{
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
        $server = new Server($transport, new ServerInfo('test', '1.0'));

        $exitCode = $server->run();

        self::assertSame(0, $exitCode);

        $output = $outputBuffer->buffer();

        // Parse responses
        self::assertStringContainsString('"id":2', $output, 'Should have response to definition request');
        // JSON escapes / as \/ so check for that
        self::assertStringContainsString('MyClass.php', $output, 'Definition should point to MyClass.php');
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

            public function read(): ?Message
            {
                return $this->reader->read();
            }

            public function write(ResponseMessage $response): void
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
