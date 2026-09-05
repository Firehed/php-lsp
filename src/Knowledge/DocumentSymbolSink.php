<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSource;

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
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly SyntaxSource $parser,
        private readonly DeclarationScanner $scanner,
        private readonly TextSymbolExtractor $textExtractor,
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
        // Three-tier producer selection (RFC 1 §5.3), cheapest first: AST when the
        // parse yields declarations, preserved registration when the parse is empty
        // and a prior one exists, text producer when neither. `MemberResolver` sees
        // one consumer regardless of tier.
        $ast = $this->parser->parse($document);
        $declarations = $this->scanner->scan($ast);
        $filePath = FileUri::toPath($document->uri);

        if ($declarations->classLikes !== [] || $declarations->functions !== [] || $declarations->constants !== []) {
            $symbols = $this->infoFactory->allIn($declarations, $filePath);
            $this->backend->updateDocument($document->uri, ...$symbols);
            $this->indexer->indexParsed($document, $ast);
            $this->assertStoresAgree($symbols);
            return;
        }

        if ($this->backend->hasRegistrationFor($document->uri)) {
            return;
        }

        $textSymbols = $this->textExtractor->extract($document->getContent(), $filePath);
        if ($textSymbols !== []) {
            $this->backend->updateDocument($document->uri, ...$textSymbols);
        }
    }

    /**
     * The lookup store and the symbol index are separate structures, so the Step P
     * parity harness — which compares only observable outputs — could stay green
     * while they diverged internally (Plan 0002 §5.5, Step 3a(iv)). This guards the
     * invariant directly: every name registered for lookup MUST also be indexed
     * (RFC 1 §4.3). The check is one-directional because the index is a superset —
     * it also records members, which are not registered for lookup.
     *
     * It earns its place because the two stores qualify a name by different routes —
     * the parser's `namespacedName` here, a hand-tracked enclosing namespace in
     * {@see \Firehed\PhpLsp\Index\SymbolExtractor} — so agreement is a property of two
     * implementations rather than of one.
     *
     * @param list<DeclaredSymbol> $symbols
     */
    private function assertStoresAgree(array $symbols): void
    {
        foreach ($symbols as $symbol) {
            $this->assertIndexed($symbol->kind, $symbol->name->fullyQualifiedName());
        }
    }

    private function assertIndexed(NameKind $kind, string $fqn): void
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
            $kind->name,
            $fqn,
        ));
        // @codeCoverageIgnoreEnd
    }
}
