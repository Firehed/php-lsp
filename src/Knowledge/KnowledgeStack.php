<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Index\CachedNamespaceCatalog;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
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

        [$workspaceMap, $vendorMap] = $autoloadMap->partitionByVendorDirectory($vendorDirectory);

        $openDocuments = new OpenDocumentBackend($index);
        $source = new CompositeSymbolSource([
            $openDocuments,
            self::filesystemBackend($workspaceMap, $parser, $classInfoFactory),
            self::filesystemBackend($vendorMap, $parser, $classInfoFactory),
            new BuiltinBackend(
                $classInfoFactory,
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
        );

        return new self($source, $sink);
    }

    private static function filesystemBackend(
        ComposerAutoloadMap $map,
        ParserService $parser,
        ClassInfoFactory $classInfoFactory,
    ): FilesystemBackend {
        return new FilesystemBackend(
            new ComposerClassLocator($map),
            new CachedNamespaceCatalog(new ComposerNamespaceSource($map), CacheFactory::inMemory()),
            $parser,
            $classInfoFactory,
            CacheFactory::inMemory(),
        );
    }
}
