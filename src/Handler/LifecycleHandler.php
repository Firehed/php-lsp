<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Capability\CapabilityNegotiator;
use Firehed\PhpLsp\Protocol\InitializeResult;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\ResponseError;

final class LifecycleHandler implements HandlerInterface
{
    private const array METHODS = [
        'initialize',
        'initialized',
        'shutdown',
        'exit',
    ];

    private bool $initialized = false;
    private bool $shutdownRequested = false;
    private ?int $exitCode = null;

    public function __construct(
        private readonly CapabilityNegotiator $negotiator,
    ) {
    }

    public function supports(string $method): bool
    {
        return in_array($method, self::METHODS, true);
    }

    public function handle(Message $message): mixed
    {
        return match ($message->method) {
            'initialize' => $this->handleInitialize($message),
            'initialized' => null,
            'shutdown' => $this->handleShutdown(),
            'exit' => $this->handleExit(),
            default => null,
        };
    }

    /**
     * The lifecycle error an inbound message must be answered with given the
     * current state, or null if it is permitted (LSP "Server lifecycle",
     * RFC 1 §4.8). `exit` is always honored so the server can terminate; the
     * `initialize` request is the only other message allowed before the server
     * is initialized.
     */
    public function lifecycleErrorFor(Message $message): ?ResponseError
    {
        if ($message->method === 'exit') {
            return null;
        }
        if ($this->shutdownRequested) {
            return ResponseError::invalidRequest();
        }
        if (!$this->initialized && $message->method !== 'initialize') {
            return ResponseError::serverNotInitialized();
        }
        return null;
    }

    public function isShutdownRequested(): bool
    {
        return $this->shutdownRequested;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    private function handleInitialize(Message $message): InitializeResult
    {
        $this->initialized = true;
        return $this->negotiator->negotiate($message);
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
