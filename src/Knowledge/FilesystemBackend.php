<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Index\NamespaceCatalogInterface;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * A {@see SymbolSourceInterface} over PHP files on disk, resolved through Composer's
 * autoload maps: the workspace's own code and vendored dependencies alike, with the
 * precedence Composer's own autoloader applies between them.
 *
 * Lookup locates the file for a name and parses that one file — no
 * `vendor/` pre-index (RFC 1 §3, lazy-first). Nothing is remembered here: a
 * {@see CachingSymbolSource} in front of this backend holds an answer while its
 * file is unchanged and drops it when the file changes.
 *
 * Namespace enumeration and prefix search both go through the same catalog: the
 * two questions are views of one index. The composer autoload maps have no
 * name→file index to answer a bare prefix, so class-like search is empty on disk
 * — project-wide search is the deferred workspace-index scope (RFC 1 §3).
 * Functions and constants are found through the autoload.files index the catalog
 * composes in, which is bounded and already in memory.
 */
final class FilesystemBackend implements SymbolSourceInterface
{
    use LooksUpByKindTrait;

    public function __construct(
        private readonly SymbolLocatorInterface $locator,
        private readonly NamespaceCatalogInterface $namespaces,
        private readonly SyntaxSourceInterface $parser,
        private readonly SourceFileReader $reader,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly DeclarationScanner $scanner,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces->childrenOf($namespace->path);
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        return $this->namespaces->searchByPrefix($prefix, $kind);
    }

    private function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface
    {
        $filePath = $this->locator->locate($name, $kind);
        if ($filePath === null) {
            return null;
        }

        $declarations = $this->scanner->scanFile($filePath, $this->reader, $this->parser);

        return $this->infoFactory->fromDeclarations($declarations, $name, $kind, $filePath);
    }
}
