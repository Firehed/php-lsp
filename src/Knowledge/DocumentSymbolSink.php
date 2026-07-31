<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Stmt;

/**
 * The single write path for open-document symbol state (RFC 1 §4.3, §5.2): document
 * lifecycle events register class metadata with the {@see OpenDocumentBackend} for
 * lookup and index the document's symbols for enumeration and search, in one place.
 *
 * The two stores are distinct structures serving different consumers (Plan 0002
 * §5.5, Step 3a(iv)), but a document event drives both from **one parse**: the sink
 * parses once and feeds that AST to the class registration and to the index alike,
 * so neither reparses. A parse failure yields no statements, clearing both stores
 * together rather than leaving one stale (RFC 1 §5.2).
 */
final class DocumentSymbolSink implements SymbolSink
{
    public function __construct(
        private readonly OpenDocumentBackend $backend,
        private readonly DocumentIndexer $indexer,
        private readonly ClassInfoFactory $classInfoFactory,
        private readonly ParserService $parser,
    ) {
    }

    public function closeDocument(string $uri): void
    {
        $this->indexer->remove($uri);
        $this->backend->removeDocument($uri);
    }

    public function openDocument(TextDocument $document): void
    {
        $this->write($document);
    }

    public function updateDocument(TextDocument $document): void
    {
        $this->write($document);
    }

    private function write(TextDocument $document): void
    {
        // One parse feeds both stores (RFC 1 §4.3): the class-lookup registration and
        // the symbol index are written transactionally from this single AST. A parse
        // failure yields no statements, so both are cleared together.
        $ast = $this->parser->parse($document) ?? [];

        $this->backend->updateDocument($document->uri, $this->classesIn($ast, $document->uri));
        $this->indexer->indexParsed($document, $ast);
    }

    /**
     * @param array<Stmt> $ast
     * @return list<ClassInfo>
     */
    private function classesIn(array $ast, string $uri): array
    {
        $classes = [];
        foreach (ScopeFinder::iterateTopLevelStatements($ast) as $stmt) {
            if ($stmt instanceof Stmt\ClassLike && $stmt->name !== null) {
                $classes[] = $this->classInfoFactory->fromAstNode($stmt, $uri);
            }
        }

        return $classes;
    }
}
