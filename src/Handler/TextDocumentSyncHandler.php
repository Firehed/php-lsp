<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\TypeInference\TypeInferenceInterface;

final class TextDocumentSyncHandler implements HandlerInterface
{
    private const array METHODS = [
        'textDocument/didOpen',
        'textDocument/didChange',
        'textDocument/didClose',
    ];

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ?DocumentIndexer $indexer = null,
        private readonly ?TypeInferenceInterface $typeInference = null,
    ) {
    }

    public function supports(string $method): bool
    {
        return in_array($method, self::METHODS, true);
    }

    public function handle(Message $message): mixed
    {
        $params = $message->params ?? [];

        return match ($message->method) {
            'textDocument/didOpen' => $this->handleDidOpen($params),
            'textDocument/didChange' => $this->handleDidChange($params),
            'textDocument/didClose' => $this->handleDidClose($params),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function handleDidOpen(array $params): null
    {
        $textDocument = $params['textDocument'] ?? [];
        assert(is_array($textDocument));

        $uri = $textDocument['uri'] ?? '';
        $languageId = $textDocument['languageId'] ?? '';
        $version = $textDocument['version'] ?? 0;
        $text = $textDocument['text'] ?? '';

        assert(is_string($uri));
        assert(is_string($languageId));
        assert(is_int($version));
        assert(is_string($text));

        $this->documentManager->open($uri, $languageId, $version, $text);
        $this->indexDocument($uri);

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function handleDidChange(array $params): null
    {
        $textDocument = $params['textDocument'] ?? [];
        assert(is_array($textDocument));

        $uri = $textDocument['uri'] ?? '';
        $version = $textDocument['version'] ?? 0;

        assert(is_string($uri));
        assert(is_int($version));

        $contentChanges = $params['contentChanges'] ?? [];
        assert(is_array($contentChanges));

        // Full sync: use last change's text
        $lastChange = end($contentChanges);
        if (is_array($lastChange) && isset($lastChange['text'])) {
            assert(is_string($lastChange['text']));
            $this->documentManager->update($uri, $lastChange['text'], $version);
            $this->indexDocument($uri);
            $this->typeInference?->invalidate($uri);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function handleDidClose(array $params): null
    {
        $textDocument = $params['textDocument'] ?? [];
        assert(is_array($textDocument));

        $uri = $textDocument['uri'] ?? '';
        assert(is_string($uri));

        $this->indexer?->remove($uri);
        $this->typeInference?->invalidate($uri);
        $this->documentManager->close($uri);

        return null;
    }

    private function indexDocument(string $uri): void
    {
        $document = $this->documentManager->get($uri);
        if ($document !== null && $this->indexer !== null) {
            $this->indexer->index($document);
        }
    }
}
