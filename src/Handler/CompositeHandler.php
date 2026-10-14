<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Protocol\Message;

/**
 * Every handler except the lifecycle one, which {@see \Firehed\PhpLsp\Server}
 * holds itself because it also gates messages and sets the exit code.
 */
final readonly class CompositeHandler implements HandlerInterface
{
    /** @var list<HandlerInterface> */
    private array $handlers;

    public function __construct(
        TextDocumentSyncHandler $sync,
        DidChangeWatchedFilesHandler $watchedFiles,
        DefinitionHandler $definition,
        HoverHandler $hover,
        SignatureHelpHandler $signatureHelp,
        CompletionHandler $completion,
    ) {
        $this->handlers = [$sync, $watchedFiles, $definition, $hover, $signatureHelp, $completion];
    }

    public function supports(string $method): bool
    {
        return $this->handlerFor($method) !== null;
    }

    public function handle(Message $message): mixed
    {
        $handler = $this->handlerFor($message->method)
            ?? throw new \LogicException("No handler supports {$message->method}");

        return $handler->handle($message);
    }

    private function handlerFor(string $method): ?HandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($method)) {
                return $handler;
            }
        }
        return null;
    }
}
