<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Handler\LifecycleHandler;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\ServerInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LifecycleHandler::class)]
#[CoversClass(ServerInfo::class)]
class LifecycleHandlerTest extends TestCase
{
    public function testSupportsLifecycleMethods(): void
    {
        $handler = new LifecycleHandler(new ServerInfo('test', '1.0'));

        self::assertTrue($handler->supports('initialize'));
        self::assertTrue($handler->supports('initialized'));
        self::assertTrue($handler->supports('shutdown'));
        self::assertTrue($handler->supports('exit'));
        self::assertFalse($handler->supports('textDocument/hover'));
    }

    public function testInitializeReturnsCapabilities(): void
    {
        $handler = new LifecycleHandler(new ServerInfo('php-lsp', '0.1.0'));

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['processId' => 1234, 'capabilities' => []],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('capabilities', $result);
        self::assertArrayHasKey('serverInfo', $result);
        self::assertIsArray($result['serverInfo']);
        self::assertSame('php-lsp', $result['serverInfo']['name']);
        self::assertSame('0.1.0', $result['serverInfo']['version']);

        // Verify textDocumentSync uses options object, not bare number
        $capabilities = $result['capabilities'];
        self::assertIsArray($capabilities);
        $sync = $capabilities['textDocumentSync'];
        self::assertIsArray($sync);
        self::assertTrue($sync['openClose']);
        self::assertSame(1, $sync['change']);
        self::assertFalse($sync['save']);
    }

    public function testInitializedReturnsNull(): void
    {
        $handler = new LifecycleHandler(new ServerInfo('test', '1.0'));

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'initialized',
            'params' => [],
        ]);

        $result = $handler->handle($notification);

        self::assertNull($result);
    }

    public function testShutdownSetsFlag(): void
    {
        $handler = new LifecycleHandler(new ServerInfo('test', '1.0'));

        self::assertFalse($handler->isShutdownRequested());

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'shutdown',
        ]);

        $result = $handler->handle($request);

        self::assertNull($result);
        self::assertTrue($handler->isShutdownRequested());
    }

    public function testExitCodeAfterShutdown(): void
    {
        $handler = new LifecycleHandler(new ServerInfo('test', '1.0'));

        // Shutdown first
        $handler->handle(RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'shutdown',
        ]));

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'exit',
        ]);

        $result = $handler->handle($notification);

        self::assertSame(0, $handler->getExitCode());
    }

    public function testExitCodeWithoutShutdown(): void
    {
        $handler = new LifecycleHandler(new ServerInfo('test', '1.0'));

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'exit',
        ]);

        $result = $handler->handle($notification);

        self::assertSame(1, $handler->getExitCode());
    }
}
