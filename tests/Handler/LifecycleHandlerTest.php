<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\CapabilityNegotiator;
use Firehed\PhpLsp\Handler\LifecycleHandler;
use Firehed\PhpLsp\Protocol\InitializeResult;
use Firehed\PhpLsp\Protocol\MarkupKind;
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
            'params' => [
                'processId' => 1234,
                'capabilities' => [
                    'textDocument' => ['hover' => ['contentFormat' => ['markdown']]],
                ],
            ],
        ]);

        $result = $handler->handle($request);

        // Observing the injected negotiator's resolved value, rather than
        // comparing against a second negotiate() call, is what makes this fail
        // if the handler shapes its own result or builds its own negotiator.
        self::assertSame(
            MarkupKind::Markdown,
            $negotiator->getSessionCapabilities()->hoverMarkupKind,
            'the handler must route initialize through the injected negotiator so the client params are resolved',
        );
        self::assertInstanceOf(
            InitializeResult::class,
            $result,
            'initialize must be answered with the negotiated result',
        );
        self::assertSame(
            'php-lsp',
            $result->serverInfo->name,
            'the result must carry the server info the negotiator was constructed with',
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
