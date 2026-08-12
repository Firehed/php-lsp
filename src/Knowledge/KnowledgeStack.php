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
use Firehed\PhpLsp\Index\DeclarationScanner;
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
    ): self {
        $index ??= new SymbolIndex();
        $classInfoFactory = new DefaultClassInfoFactory();
        $declarationInfoFactory = new DeclarationSymbolInfoFactory($classInfoFactory);

        [$workspaceMap, $vendorMap] = $autoloadMap->partitionByVendorDirectory($vendorDirectory);

        $scanner = new DeclarationScanner();

        $openDocuments = new OpenDocumentBackend($index);
        $workspace = self::filesystemBackend($workspaceMap, $parser, $declarationInfoFactory, $scanner);
        $vendor = self::filesystemBackend($vendorMap, $parser, $declarationInfoFactory, $scanner);
        $source = new CompositeSymbolSource([
            $openDocuments,
            $workspace,
            $vendor,
            new BuiltinBackend(
                new ReflectionSymbolInfoFactory($classInfoFactory),
                new CachedNamespaceCatalog(new ReflectionNamespaceSource(), CacheFactory::inMemory()),
                CacheFactory::inMemory(),
            ),
        ]);

        $sink = new DocumentSymbolSink(
            $openDocuments,
            new DocumentIndexer($parser, new SymbolExtractor(), $index),
            $index,
            $classInfoFactory,
            $parser,
            $scanner,
            // External-change and close-after-edit invalidation drops the on-disk
            // cache for a file so the next query re-reads disk (RFC 1 §5.2, §5.3).
            // The open-document backend is authoritative and never cached, so it is
            // not invalidated; the built-in backend does not read workspace files.
            [$workspace, $vendor],
        );

        return new self($source, $sink);
    }

    /**
     * The two routes to a declaration, cheapest first: Composer's autoload maps
     * address class-likes by arithmetic on the name, and the derived index covers
     * the `autoload.files` set, which they address by no name at all (Plan 0002 §3).
     *
     * Both routes answer enumeration as well as lookup, from the one derived index
     * — the `files` set sits outside every PSR-4 and PSR-0 prefix, so the directory
     * listing cannot see it, and a name reachable by only one of the two surfaces is
     * the split RFC 1 §4.2 forbids.
     */
    private static function filesystemBackend(
        ComposerAutoloadMap $map,
        ParserService $parser,
        DeclarationSymbolInfoFactory $infoFactory,
        DeclarationScanner $scanner,
    ): FilesystemBackend {
        $autoloadFiles = new AutoloadFilesLocator($map, $parser, $scanner);

        return new FilesystemBackend(
            new CompositeSymbolLocator([
                new ComposerSymbolLocator($map),
                $autoloadFiles,
            ]),
            new CachedNamespaceCatalog(
                new CompositeNamespaceCatalog([
                    new ComposerNamespaceSource($map),
                    $autoloadFiles,
                ]),
                CacheFactory::inMemory(),
            ),
            $parser,
            $infoFactory,
            $scanner,
            CacheFactory::inMemory(),
        );
    }
}
