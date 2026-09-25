<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
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
     * Build the stack for a project. Precedence in the composite: an open
     * document overrides the autoload.files set, which overrides the file on
     * disk resolved through Composer's maps, which overrides the built-ins
     * (RFC 1 §5.3). Composer requires the files entries before the autoloader
     * is ever asked, so a name declared there wins over the name -> file map.
     * On-disk and built-in enumeration is cached; open documents and the
     * files-set index never are.
     */
    public static function forProject(
        ComposerAutoloadMap $autoloadMap,
        SyntaxSourceInterface $parser,
        SourceFileReader $reader,
    ): self {
        $declarationInfoFactory = new DeclarationSymbolInfoFactory();
        $scanner = new DeclarationScanner();

        $openDocuments = new OpenDocumentBackend();
        $autoloadFiles = new AutoloadFilesBackend(
            $autoloadMap,
            $declarationInfoFactory,
            $scanner,
            $reader,
            $parser,
        );
        $disk = new CachingSymbolSource(
            new ComposerMapBackend(
                $autoloadMap,
                $parser,
                $reader,
                $declarationInfoFactory,
                $scanner,
            ),
            CacheFactory::inMemory(),
        );

        // The built-in backend owns its own derived index of internal symbols, so
        // enumeration and prefix search draw on the same source and cannot disagree
        // about which names count as built-in (§4.2). The CachingSymbolSource
        // decorator caches its childrenOf lookups.
        $source = new CompositeSymbolSource([
            $openDocuments,
            $autoloadFiles,
            $disk,
            new CachingSymbolSource(
                new BuiltinBackend(),
                CacheFactory::inMemory(),
            ),
        ]);

        $sink = new DocumentSymbolSink(
            $openDocuments,
            $declarationInfoFactory,
            $parser,
            $scanner,
            // External-change and close-after-edit invalidation drops the on-disk
            // cache for a file and rebuilds the files-set index when a member of
            // it changed (RFC 1 §5.2, §5.3). The open-document backend is
            // authoritative and never cached, so it is not invalidated; the
            // built-in backend does not read workspace files.
            [$disk, $autoloadFiles],
        );

        return new self($source, $sink);
    }
}
