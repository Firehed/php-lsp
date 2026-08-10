<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Index\DeclarationScanner;
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
    /**
     * @param list<Invalidatable> $onDiskBackends the cached on-disk backends
     *        (workspace, vendor) whose entry for a file must be dropped when that
     *        file changes on disk or is closed after being edited (RFC 1 §5.2, §5.3)
     */
    public function __construct(
        private readonly OpenDocumentBackend $backend,
        private readonly DocumentIndexer $indexer,
        private readonly SymbolIndex $index,
        private readonly ClassInfoFactory $classInfoFactory,
        private readonly ParserService $parser,
        private readonly DeclarationScanner $scanner,
        private readonly array $onDiskBackends = [],
    ) {
    }

    public function closeDocument(string $uri): void
    {
        $this->indexer->remove($uri);
        $this->backend->removeDocument($uri);

        // Closing a file that was edited in the editor must re-read from disk on
        // the next query rather than restore the pre-edit cached value (RFC 1 §5.3):
        // the open-document answer is gone, so drop any stale on-disk cache too.
        $this->invalidate($uri);
    }

    public function invalidate(string $uri): void
    {
        foreach ($this->onDiskBackends as $backend) {
            $backend->invalidate($uri);
        }
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
        $functions = $this->functionsIn($ast, $document->uri);
        $this->backend->updateDocument($document->uri, $classes, $functions);
        $this->indexer->indexParsed($document, $ast);

        $this->assertStoresAgree($classes, $functions);
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
     * Functions are checked on the same terms, and need it more: the two stores
     * qualify a function name by different routes — the parser's `namespacedName`
     * here, a hand-tracked enclosing namespace in {@see \Firehed\PhpLsp\Index\SymbolExtractor} —
     * so agreement is a property of two implementations rather than of one.
     *
     * @param list<ClassInfo> $classes
     * @param array<string, FunctionInfo> $functions
     */
    private function assertStoresAgree(array $classes, array $functions): void
    {
        foreach ($classes as $classInfo) {
            $this->assertIndexed('class-like', $classInfo->name->fqn);
        }

        foreach (array_keys($functions) as $fqn) {
            $this->assertIndexed('function', $fqn);
        }
    }

    private function assertIndexed(string $kind, string $fqn): void
    {
        if ($this->index->findByFqn($fqn) !== null) {
            return;
        }

        // Unreachable: both stores derive their symbols from the one AST parsed
        // above, so a name registered for lookup is always indexed. The guard fails
        // loudly if that ever ceases to hold.
        // @codeCoverageIgnoreStart
        throw new \LogicException(sprintf(
            'Write-path divergence: %s %s is registered for lookup but absent from the '
            . 'symbol index; the two stores are written from one parse and must agree '
            . '(RFC 1 §4.3).',
            $kind,
            $fqn,
        ));
        // @codeCoverageIgnoreEnd
    }

    /**
     * A declaration at any depth counts, matching what the on-disk backends resolve
     * (a polyfill guarded by `function_exists` is the common shape). Opening a file
     * must not make a name that already resolved disappear (RFC 1 §4.2).
     *
     * @param array<Stmt> $ast
     * @return array<string, FunctionInfo> Fully-qualified name -> metadata
     */
    private function functionsIn(array $ast, string $uri): array
    {
        $functions = [];
        foreach ($this->scanner->scan($ast)->functions as $declaration) {
            $fqn = $declaration->name->fullyQualifiedName();
            $functions[$fqn] ??= FunctionInfo::fromNode($declaration->node, $uri);
        }

        return $functions;
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
