<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Stmt;

/**
 * The Step-2 {@see SymbolSource} / {@see SymbolSink} implementation: pure delegation
 * to today's collaborators, introducing the read/write seam (RFC 1 §4.2, §4.3) with
 * no behavior change (Plan 0002 §5.5).
 *
 * The write path deliberately reproduces today's *double* write — the class
 * repository and the symbol index are two stores fed from one document — behind a
 * single method. Collapsing them to one parse and one write is Step 3a; here the two
 * writes are preserved exactly so the Step P parity harness is identical before and
 * after (Plan 0002 §5.5, Teardown ledger).
 */
final class DelegatingSymbolSource implements SymbolSource, SymbolSink
{
    /**
     * The symbol kinds that are class-likes. searchClassLikes searches this namespace
     * alone; the function and constant namespaces are Step 3b (Plan 0002 §5.3).
     *
     * @var list<SymbolKind>
     */
    private const array CLASS_LIKE_KINDS = [
        SymbolKind::Class_,
        SymbolKind::Enum_,
        SymbolKind::Interface_,
        SymbolKind::Trait_,
    ];

    public function __construct(
        private readonly ClassRepository $classes,
        private readonly SymbolIndex $index,
        private readonly NamespaceCatalog $catalog,
        private readonly DocumentIndexer $indexer,
        private readonly ClassInfoFactory $classInfoFactory,
        private readonly ParserService $parser,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->catalog->childrenOf($namespace->path);
    }

    public function closeDocument(string $uri): void
    {
        $this->indexer->remove($uri);
        $this->classes->removeDocument($uri);
    }

    public function isSubclassOf(ClassName $class, ClassName $potentialParent): bool
    {
        return $this->classes->isSubclassOf($class, $potentialParent);
    }

    public function lookupClassLike(ClassName $name): ?ClassInfo
    {
        return $this->classes->get($name);
    }

    public function openDocument(TextDocument $document): void
    {
        $this->writeDocument($document);
    }

    /**
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array
    {
        return $this->index->findByPrefix($prefix, self::CLASS_LIKE_KINDS);
    }

    public function updateDocument(TextDocument $document): void
    {
        $this->writeDocument($document);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function registerClasses(string $uri, array $ast): void
    {
        $classes = [];
        foreach (ScopeFinder::iterateTopLevelStatements($ast) as $stmt) {
            if ($stmt instanceof Stmt\ClassLike && $stmt->name !== null) {
                $classes[] = $this->classInfoFactory->fromAstNode($stmt, $uri);
            }
        }
        $this->classes->updateDocument($uri, $classes);
    }

    private function writeDocument(TextDocument $document): void
    {
        $ast = $this->parser->parse($document);
        if ($ast !== null) {
            $this->registerClasses($document->uri, $ast);
        }

        $this->indexer->index($document);
    }
}
