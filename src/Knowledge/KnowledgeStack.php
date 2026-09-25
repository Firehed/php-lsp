<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * Assembles the symbol-knowledge tier: the {@see SymbolSourceInterface} read composite
 * over its fixed backend precedence, the {@see SymbolSinkInterface} write path for open
 * documents, and the {@see InvalidatableInterface} fan-out that drops cached on-disk
 * state (RFC 1 §4.2, §4.3, §5.2, §5.3).
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
        public InvalidatableInterface $invalidator,
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

        $openDocuments = new OpenDocumentBackend($parser, $scanner, $declarationInfoFactory);
        $autoloadFiles = new AutoloadFilesBackend(
            $autoloadMap,
            $declarationInfoFactory,
            $scanner,
            $reader,
            $parser,
        );
        $composerMap = new ComposerMapBackend(
            $autoloadMap,
            $parser,
            $reader,
            $declarationInfoFactory,
            $scanner,
        );
        $disk = new CachingSymbolSource($composerMap, CacheFactory::inMemory());

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

        return new self(
            $source,
            $openDocuments,
            new CompositeInvalidatable($disk, $composerMap, $autoloadFiles),
        );
    }
}
