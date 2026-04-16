<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Index\SymbolExtractor;
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
        private readonly ?ComposerClassLocator $classLocator = null,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/definition';
    }

    /**
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
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
        if (!$node instanceof Name) {
            return null;
        }

        // Use the resolved name if available (handles use statements)
        $resolvedName = $node->getAttribute('resolvedName');
        $symbolName = $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $node->toString();

        // Look up in index first (for open files)
        $symbol = $this->symbolIndex->findByFqn($symbolName);
        if ($symbol === null) {
            $matches = $this->symbolIndex->findByName($symbolName);
            $symbol = $matches[0] ?? null;
        }

        if ($symbol !== null) {
            return $symbol->location->toLspLocation();
        }

        // Not in index - try to locate via Composer autoload
        return $this->locateViaComposer($symbolName)?->toLspLocation();
    }

    private function locateViaComposer(string $className): ?Location
    {
        if ($this->classLocator === null) {
            return null;
        }

        $filePath = $this->classLocator->locateClass($className);
        if ($filePath === null) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $uri = 'file://' . $filePath;
        $document = new TextDocument($uri, 'php', 0, $content);

        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        // Extract symbols and find our class
        $extractor = new SymbolExtractor();
        $symbols = $extractor->extract($document, $ast);

        foreach ($symbols as $symbol) {
            if ($symbol->fullyQualifiedName === $className) {
                return $symbol->location;
            }
        }

        // Fallback: return start of file
        return new Location($uri, 0, 0, 0, 0);
    }
}
