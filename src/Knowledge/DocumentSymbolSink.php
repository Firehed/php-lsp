<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Stmt;

/**
 * The single write path for open-document symbol state (RFC 1 §4.3, §5.2): document
 * lifecycle events register class metadata with the {@see OpenDocumentBackend} and
 * index the document's symbols in one place.
 *
 * It still performs today's *double* write — full {@see \Firehed\PhpLsp\Domain\ClassInfo}
 * for lookup, lightweight symbols for enumeration and search, fed from one document.
 * Collapsing the two into one parse with a consistency check is Step 3a(iv) (Plan
 * 0002 §5.5, Teardown ledger); here both are driven from the same document so a
 * parse failure clears both rather than leaving one stale.
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
        $this->registerClasses($document);
        $this->indexer->index($document);
    }

    private function registerClasses(TextDocument $document): void
    {
        // A parse failure yields no classes rather than skipping registration, so the
        // backend is cleared for this document exactly as the index is (RFC 1 §5.2):
        // both stores move together instead of one keeping a stale answer.
        $ast = $this->parser->parse($document) ?? [];

        $classes = [];
        foreach (ScopeFinder::iterateTopLevelStatements($ast) as $stmt) {
            if ($stmt instanceof Stmt\ClassLike && $stmt->name !== null) {
                $classes[] = $this->classInfoFactory->fromAstNode($stmt, $document->uri);
            }
        }

        $this->backend->updateDocument($document->uri, $classes);
    }
}
