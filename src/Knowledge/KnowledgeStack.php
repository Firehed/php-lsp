<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Document\DocumentSourceInterface;
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
    // Working-set target: a typical editing session touches fewer files than
    // this, so nothing evicts. Not derived from measurement; picked as a
    // comfortable bound above the observed working set.
    private const int WORKING_SET_FILES = 2000;

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
     */
    public static function forProject(
        ComposerAutoloadMapReader $mapReader,
        SyntaxSourceInterface $parser,
        DocumentSourceInterface $reader,
    ): self {
        $declarations = new CachingDeclarationSource(
            new ParsedDeclarationSource(
                $parser,
                new DeclarationScanner(),
                new DeclarationSymbolInfoFactory(),
            ),
            CacheFactory::inMemory(maxItems: self::WORKING_SET_FILES),
        );

        $openDocuments = new OpenDocumentBackend($declarations);
        $autoloadFiles = new AutoloadFilesBackend($mapReader, $reader, $declarations);
        $composerMap = new ComposerMapBackend($mapReader, $reader, $declarations);

        return new self(
            new CompositeSymbolSource($openDocuments, $autoloadFiles, $composerMap, new BuiltinBackend()),
            $openDocuments,
            new CompositeInvalidatable($mapReader, $composerMap, $autoloadFiles),
        );
    }
}
