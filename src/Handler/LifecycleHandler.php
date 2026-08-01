<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Capability\CapabilityNegotiator;
use Firehed\PhpLsp\Capability\InitializedListener;
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

    /**
     * @param list<InitializedListener> $initializedListeners invoked once the
     *        client sends `initialized`, when the negotiated session capabilities
     *        are settled and dynamic registration may proceed ([LSP] Register
     *        Capability)
     */
    public function __construct(
        private readonly CapabilityNegotiator $negotiator,
        private readonly array $initializedListeners = [],
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
            'initialized' => $this->handleInitialized(),
            'shutdown' => $this->handleShutdown(),
            'exit' => $this->handleExit(),
            default => null,
        };
    }

    /**
     * The lifecycle error an inbound message must be answered with given the
     * current state, or null if it is permitted (LSP "Server lifecycle",
     * RFC 1 §4.8). `exit` is always honored so the server can terminate. Before
     * the server is initialized, `initialize` is the only message allowed; it
     * "may only be sent once" (LSP), so a second one is rejected rather than
     * re-running negotiation over the already-resolved session.
     */
    public function lifecycleErrorFor(Message $message): ?ResponseError
    {
        if ($message->method === 'exit') {
            return null;
        }
        if ($this->shutdownRequested) {
            return ResponseError::invalidRequest();
        }
        if ($message->method === 'initialize') {
            return $this->initialized ? ResponseError::invalidRequest() : null;
        }
        if (!$this->initialized) {
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

    /**
     * The gate opens only once negotiation has produced a result. A negotiation
     * that failed would be answered with InternalError, so the client never
     * receives an InitializeResult and by LSP "Server lifecycle" is still
     * pre-initialize; the flag must not claim otherwise.
     */
    private function handleInitialize(Message $message): InitializeResult
    {
        $result = $this->negotiator->negotiate($message);
        $this->initialized = true;

        return $result;
    }

    /**
     * The client's `initialized` notification follows a successful `initialize`,
     * so the negotiated session capabilities are settled. Post-initialize actions
     * (dynamic capability registration) run here against those capabilities.
     */
    private function handleInitialized(): null
    {
        $capabilities = $this->negotiator->getSessionCapabilities();
        foreach ($this->initializedListeners as $listener) {
            $listener->onInitialized($capabilities);
        }

        return null;
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
