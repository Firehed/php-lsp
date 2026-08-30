<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Index\AutoloadFilesLocator;
use Firehed\PhpLsp\Index\CachedNamespaceCatalog;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\ComposerSymbolLocator;
use Firehed\PhpLsp\Index\CompositeNamespaceCatalog;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;

/**
 * Assembles the symbol-knowledge tier: the {@see SymbolSource} read composite over
 * its fixed backend precedence, and the {@see SymbolSink} write path, sharing one
 * open-document backend and symbol index (RFC 1 §4.2, §4.3, §5.3).
 *
 * The wiring lives here, in one place, so the composition root ({@see \Firehed\PhpLsp\Server})
 * and the tests that exercise the surfaces (parity, handlers) build the same stack
 * rather than each re-assembling the backends by hand.
 */
final readonly class KnowledgeStack
{
    public function __construct(
        public SymbolSource $source,
        public SymbolSink $sink,
    ) {
    }

    /**
     * Build the stack for a project. The autoload map is split into the project's
     * own code and its dependencies (RFC 1 §5.3): an open document overrides the
     * workspace, which overrides vendored code, which overrides the built-ins.
     * On-disk and built-in enumeration is cached; open documents never are.
     *
     * An existing symbol index may be supplied so a caller can pre-populate the
     * open-document state; otherwise a fresh one is created and driven by the sink.
     */
    public static function forProject(
        ComposerAutoloadMap $autoloadMap,
        string $vendorDirectory,
        ParserService $parser,
        ?SymbolIndex $index = null,
        ?TextSymbolExtractor $textExtractor = null,
    ): self {
        $textExtractor ??= new NullTextSymbolExtractor();
        $index ??= new SymbolIndex();
        $classInfoFactory = new DefaultClassInfoFactory();
        $declarationInfoFactory = new DeclarationSymbolInfoFactory($classInfoFactory);

        [$workspaceMap, $vendorMap] = $autoloadMap->partitionByVendorDirectory($vendorDirectory);

        $scanner = new DeclarationScanner();

        $openDocuments = new OpenDocumentBackend($index);
        [$workspace, $workspaceInvalidatables] = self::filesystemBackend(
            $workspaceMap,
            $parser,
            $declarationInfoFactory,
            $scanner,
        );
        [$vendor, $vendorInvalidatables] = self::filesystemBackend(
            $vendorMap,
            $parser,
            $declarationInfoFactory,
            $scanner,
        );
        // ReflectionNamespaceSource serves both enumeration (cached, via
        // NamespaceCatalog) and prefix search (uncached, via PrefixSearchable).
        // Both must draw on the same source so coverage is identical (§4.2).
        $reflectionSource = new ReflectionNamespaceSource();
        $source = new CompositeSymbolSource([
            $openDocuments,
            $workspace,
            $vendor,
            new BuiltinBackend(
                new ReflectionSymbolInfoFactory($classInfoFactory),
                new CachedNamespaceCatalog($reflectionSource, CacheFactory::inMemory()),
                new SymbolCache(CacheFactory::inMemory()),
                $reflectionSource,
            ),
        ]);

        $sink = new DocumentSymbolSink(
            $openDocuments,
            new DocumentIndexer($parser, new SymbolExtractor(), $index),
            $index,
            $declarationInfoFactory,
            $parser,
            $scanner,
            $textExtractor,
            // External-change and close-after-edit invalidation drops the on-disk
            // cache for a file so the next query re-reads disk (RFC 1 §5.2, §5.3).
            // The open-document backend is authoritative and never cached, so it is
            // not invalidated; the built-in backend does not read workspace files.
            [...$workspaceInvalidatables, ...$vendorInvalidatables],
        );

        return new self($source, $sink);
    }

    /**
     * @return array{FilesystemBackend, list<Invalidatable>}
     */
    private static function filesystemBackend(
        ComposerAutoloadMap $map,
        ParserService $parser,
        DeclarationSymbolInfoFactory $infoFactory,
        DeclarationScanner $scanner,
    ): array {
        // AutoloadFilesLocator serves three roles: symbol location, namespace
        // enumeration (composed into the catalog), and prefix search. All three
        // must be the same instance so coverage is identical (§4.2) and
        // invalidation propagates to search results.
        $autoloadFiles = new AutoloadFilesLocator($map, $parser, $scanner);
        $cachedCatalog = new CachedNamespaceCatalog(
            new CompositeNamespaceCatalog([
                new ComposerNamespaceSource($map),
                $autoloadFiles,
            ]),
            CacheFactory::inMemory(),
        );

        $backend = new FilesystemBackend(
            new CompositeSymbolLocator([
                new ComposerSymbolLocator($map),
                $autoloadFiles,
            ]),
            $cachedCatalog,
            $parser,
            $infoFactory,
            $scanner,
            new SymbolCache(CacheFactory::inMemory()),
            $autoloadFiles,
        );

        return [$backend, [$backend, $cachedCatalog, $autoloadFiles]];
    }
}
