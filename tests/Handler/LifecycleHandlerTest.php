<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\CapabilityNegotiator;
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
        $handler = self::handler();

        self::assertTrue($handler->supports('initialize'));
        self::assertTrue($handler->supports('initialized'));
        self::assertTrue($handler->supports('shutdown'));
        self::assertTrue($handler->supports('exit'));
        self::assertFalse($handler->supports('textDocument/hover'));
    }

    public function testInitializeDelegatesToTheNegotiator(): void
    {
        $negotiator = new CapabilityNegotiator(new ServerInfo('php-lsp', '0.1.0'));
        $handler = new LifecycleHandler($negotiator);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['processId' => 1234, 'capabilities' => []],
        ]);

        $result = $handler->handle($request);

        self::assertEquals(
            $negotiator->negotiate($request),
            $result,
            'the handler must return the negotiator result rather than shape one itself',
        );
    }

    public function testInitializedReturnsNull(): void
    {
        $handler = self::handler();

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
        $handler = self::handler();

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
        $handler = self::handler();

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
        $handler = self::handler();

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'exit',
        ]);

        $result = $handler->handle($notification);

        self::assertSame(1, $handler->getExitCode());
    }

    private static function handler(): LifecycleHandler
    {
        return new LifecycleHandler(new CapabilityNegotiator(new ServerInfo('test', '1.0')));
    }
}
