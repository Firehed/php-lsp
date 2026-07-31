<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Knowledge\SymbolSink;
use Firehed\PhpLsp\Protocol\Message;

/**
 * Handles the `workspace/didChangeWatchedFiles` notification ([LSP] "Workspace
 * Features" → `workspace/didChangeWatchedFiles`): the client reports on-disk
 * changes to files it watches on the server's behalf, which the server registers
 * for dynamically (there is no static server option for it — [LSP] Register
 * Capability).
 *
 * Every reported change — created, changed, or deleted ([LSP] `FileChangeType`) —
 * invalidates the affected file's cached workspace state through the write path
 * (RFC 1 §5.2), so the next query re-reads disk: a changed file re-parses, a
 * deleted file resolves to nothing, and a created file appears in enumeration. The
 * change type is not inspected because the response to all three is the same drop.
 */
final class DidChangeWatchedFilesHandler implements HandlerInterface
{
    private const string METHOD = 'workspace/didChangeWatchedFiles';

    public function __construct(
        private readonly SymbolSink $symbols,
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

            $this->symbols->invalidate($uri);
        }

        return null;
    }
}
