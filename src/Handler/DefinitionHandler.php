<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use PhpParser\Node\Name;

final class DefinitionHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly SymbolIndex $symbolIndex,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/definition';
    }

    /**
     * @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}|null
     */
    public function handle(Message $message): ?array
    {
        $params = $message->params ?? [];

        $textDocument = $params['textDocument'] ?? [];
        if (!is_array($textDocument)) {
            return null;
        }
        $uri = $textDocument['uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }

        $position = $params['position'] ?? [];
        if (!is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        if (!is_int($line) || !is_int($character)) {
            return null;
        }

        // Get the document
        $document = $this->documentManager->get($uri);
        if ($document === null) {
            return null;
        }

        // Parse the document
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        // Find node at position
        $offset = $document->offsetAt($line, $character);
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find($ast, $offset);

        if ($node === null) {
            return null;
        }

        // Extract the symbol name we're looking for
        $symbolName = null;

        if ($node instanceof Name) {
            // Class name reference (new MyClass, extends MyClass, etc)
            $symbolName = $node->toString();
        }

        if ($symbolName === null) {
            return null;
        }

        // Look up in index - try FQN first, then by name
        $symbol = $this->symbolIndex->findByFqn($symbolName);
        if ($symbol === null) {
            $matches = $this->symbolIndex->findByName($symbolName);
            $symbol = $matches[0] ?? null;
        }

        if ($symbol === null) {
            return null;
        }

        return $symbol->location->toLspLocation();
    }
}
