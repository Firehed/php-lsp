<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Index\AutoloadFilesLocator;
use Firehed\PhpLsp\Index\CachedNamespaceCatalog;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\ComposerSymbolLocator;
use Firehed\PhpLsp\Index\CompositeNamespaceCatalog;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * Assembles the symbol-knowledge tier: the {@see SymbolSourceInterface} read composite over
 * its fixed backend precedence, and the {@see SymbolSinkInterface} write path, sharing one
 * open-document backend (RFC 1 §4.2, §4.3, §5.3).
 *
 * The wiring lives here, in one place, so the composition root ({@see \Firehed\PhpLsp\Server})
 * and the tests that exercise the surfaces (parity, handlers) build the same stack
 * rather than each re-assembling the backends by hand.
 */
final readonly class KnowledgeStack
{
    public function __construct(
        public SymbolSourceInterface $source,
        public SymbolSinkInterface $sink,
    ) {
    }

    /**
     * Build the stack for a project: an open document overrides the file on disk,
     * which overrides the built-ins (RFC 1 §5.3). On disk, a name resolves to the
     * file Composer's own autoloader would load. On-disk and built-in enumeration
     * is cached; open documents never are.
     */
    public static function forProject(
        ComposerAutoloadMap $autoloadMap,
        SyntaxSourceInterface $parser,
        SourceFileReader $reader,
    ): self {
        $declarationInfoFactory = new DeclarationSymbolInfoFactory();
        $scanner = new DeclarationScanner();

        $openDocuments = new OpenDocumentBackend();

        // AutoloadFilesLocator serves three roles: symbol location, namespace
        // enumeration (composed into the catalog), and prefix search. All three
        // must be the same instance so coverage is identical (§4.2) and
        // invalidation propagates to search results. It precedes the maps in both
        // composites because the runtime requires every files entry before the
        // autoloader is ever asked, so a declaration there wins.
        $autoloadFiles = new AutoloadFilesLocator($autoloadMap, $parser, $reader, $scanner);
        $cachedCatalog = new CachedNamespaceCatalog(
            new CompositeNamespaceCatalog([
                $autoloadFiles,
                new ComposerNamespaceSource($autoloadMap),
            ]),
            CacheFactory::inMemory(),
        );
        $disk = new FilesystemBackend(
            new CompositeSymbolLocator([
                $autoloadFiles,
                new ComposerSymbolLocator($autoloadMap),
            ]),
            $cachedCatalog,
            $parser,
            $reader,
            $declarationInfoFactory,
            $scanner,
            new SymbolCache(CacheFactory::inMemory()),
            $autoloadFiles,
        );

        // ReflectionNamespaceSource serves both enumeration (cached, via
        // NamespaceCatalogInterface) and prefix search (uncached, via PrefixSearchableInterface).
        // Both must draw on the same source so coverage is identical (§4.2).
        $reflectionSource = new ReflectionNamespaceSource();
        $source = new CompositeSymbolSource([
            $openDocuments,
            $disk,
            new BuiltinBackend(
                new CachedNamespaceCatalog($reflectionSource, CacheFactory::inMemory()),
                new SymbolCache(CacheFactory::inMemory()),
                $reflectionSource,
            ),
        ]);

        $sink = new DocumentSymbolSink(
            $openDocuments,
            $declarationInfoFactory,
            $parser,
            $scanner,
            // External-change and close-after-edit invalidation drops the on-disk
            // cache for a file so the next query re-reads disk (RFC 1 §5.2, §5.3).
            // The open-document backend is authoritative and never cached, so it is
            // not invalidated; the built-in backend does not read workspace files.
            [$disk, $cachedCatalog, $autoloadFiles],
        );

        return new self($source, $sink);
    }
}
