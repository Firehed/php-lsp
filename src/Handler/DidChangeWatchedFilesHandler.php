<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Events\WatchedFileChangedEvent;
use Firehed\PhpLsp\Protocol\Message;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Handles `workspace/didChangeWatchedFiles`: the client reports on-disk changes to
 * files it watches for the server. Every change — created, changed, or deleted —
 * publishes a {@see WatchedFileChangedEvent} whose subscribers drop derived state
 * for the URI so the next query re-reads disk (RFC 1 §5.2). The change type is
 * not inspected because the response to all three is the same drop.
 */
final class DidChangeWatchedFilesHandler implements HandlerInterface
{
    private const string METHOD = 'workspace/didChangeWatchedFiles';

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === self::METHOD;
    }

    public function handle(Message $message): mixed
    {
        $params = $message->params ?? [];

        $changes = $params['changes'] ?? [];
        assert(is_array($changes));

        foreach ($changes as $change) {
            assert(is_array($change));
            $uri = $change['uri'] ?? '';
            assert(is_string($uri));

            $this->dispatcher->dispatch(new WatchedFileChangedEvent($uri));
        }

        return null;
    }
}
