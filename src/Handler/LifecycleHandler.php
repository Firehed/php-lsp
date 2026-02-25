<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\ServerInfo;

final class LifecycleHandler implements HandlerInterface
{
    private const array METHODS = [
        'initialize',
        'initialized',
        'shutdown',
        'exit',
    ];

    private bool $shutdownRequested = false;
    private ?int $exitCode = null;

    public function __construct(
        private readonly ServerInfo $serverInfo,
    ) {
    }

    public function supports(string $method): bool
    {
        return in_array($method, self::METHODS, true);
    }

    public function handle(Message $message): mixed
    {
        return match ($message->method) {
            'initialize' => $this->handleInitialize(),
            'initialized' => null,
            'shutdown' => $this->handleShutdown(),
            'exit' => $this->handleExit(),
            default => null,
        };
    }

    public function isShutdownRequested(): bool
    {
        return $this->shutdownRequested;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * @return array{capabilities: array<string, mixed>, serverInfo: array{name: string, version: string}}
     */
    private function handleInitialize(): array
    {
        return [
            'capabilities' => [
                // TextDocumentSyncKind.Full = 1 (send full document on change)
                'textDocumentSync' => 1,
            ],
            'serverInfo' => $this->serverInfo->toArray(),
        ];
    }

    private function handleShutdown(): null
    {
        $this->shutdownRequested = true;
        return null;
    }

    private function handleExit(): null
    {
        $this->exitCode = $this->shutdownRequested ? 0 : 1;
        return null;
    }
}
