<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolIndex;
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
        private readonly SymbolIndex $index,
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

        $classes = $this->classesIn($ast, $document->uri);
        $this->backend->updateDocument($document->uri, $classes);
        $this->indexer->indexParsed($document, $ast);

        $this->assertStoresAgree($classes);
    }

    /**
     * The lookup store and the symbol index are separate structures, so the Step P
     * parity harness — which compares only observable outputs — could stay green
     * while they diverged internally (Plan 0002 §5.5, Step 3a(iv)). This guards the
     * invariant directly: every class-like registered for lookup MUST also be
     * indexed, so a name is never resolvable through one surface yet invisible to the
     * other (RFC 1 §4.3). The check is one-directional because the index is a
     * superset — it also records class-likes declared inside conditional or nested
     * statements, which the top-level lookup registration does not.
     *
     * @param list<ClassInfo> $classes
     */
    private function assertStoresAgree(array $classes): void
    {
        foreach ($classes as $classInfo) {
            if ($this->index->findByFqn($classInfo->name->fqn) === null) {
                // Unreachable: both stores derive their class-likes from the one AST
                // parsed above, so a class registered for lookup is always indexed. The
                // guard fails loudly if that ever ceases to hold.
                // @codeCoverageIgnoreStart
                throw new \LogicException(sprintf(
                    'Write-path divergence: class-like %s is registered for lookup but absent '
                    . 'from the symbol index; the two stores are written from one parse and '
                    . 'must agree (RFC 1 §4.3).',
                    $classInfo->name->fqn,
                ));
                // @codeCoverageIgnoreEnd
            }
        }
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
