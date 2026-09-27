<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Events\EventDispatcher;
use Firehed\PhpLsp\Events\ListenerProvider;
use Firehed\PhpLsp\Events\OpenDocumentClosedEvent;
use Firehed\PhpLsp\Events\WatchedFileChangedEvent;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Assembles the symbol-knowledge tier: the {@see SymbolSourceInterface} read composite
 * over its fixed backend precedence, the {@see SymbolSinkInterface} write path for open
 * documents, and the {@see EventDispatcherInterface} that carries invalidation events
 * to the subscribers that drop cached on-disk state (RFC 1 §4.2, §4.3, §5.2, §5.3).
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
        public EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Build the stack from a project directory on disk. Precedence in the
     * composite: an open document overrides the autoload.files set, which
     * overrides the file on disk resolved through Composer's maps, which
     * overrides the built-ins (RFC 1 §5.3). Composer requires the files
     * entries before the autoloader is ever asked, so a name declared there
     * wins over the name -> file map. On-disk and built-in enumeration is
     * cached; open documents and the files-set index never are.
     */
    public static function forProject(
        string $projectRoot,
        SyntaxSourceInterface $parser,
        SourceFileReader $reader,
    ): self {
        return self::assemble(
            static fn(EventDispatcherInterface $dispatcher): ComposerAutoloadMapReader
                => new ComposerAutoloadMapReader($projectRoot, $dispatcher),
            $parser,
            $reader,
        );
    }

    /**
     * Build the stack from a hand-built autoload map, for tests that do not
     * point at a real project on disk. Precedence and behavior otherwise match
     * {@see forProject()}.
     */
    public static function forMap(
        ComposerAutoloadMap $map,
        SyntaxSourceInterface $parser,
        SourceFileReader $reader,
    ): self {
        return self::assemble(
            static fn(EventDispatcherInterface $dispatcher): ComposerAutoloadMapReader
                => ComposerAutoloadMapReader::fromMap($map, $dispatcher),
            $parser,
            $reader,
        );
    }

    /**
     * @param callable(EventDispatcherInterface): ComposerAutoloadMapReader $readerFactory
     */
    private static function assemble(
        callable $readerFactory,
        SyntaxSourceInterface $parser,
        SourceFileReader $reader,
    ): self {
        $listeners = new ListenerProvider();
        $dispatcher = new EventDispatcher($listeners);
        $mapReader = $readerFactory($dispatcher);

        $listeners->addListener(WatchedFileChangedEvent::class, $mapReader->onWatchedFileChanged(...));

        $declarationInfoFactory = new DeclarationSymbolInfoFactory();
        $scanner = new DeclarationScanner();

        $openDocuments = new OpenDocumentBackend($parser, $scanner, $declarationInfoFactory);
        $autoloadFiles = new AutoloadFilesBackend(
            $mapReader->current(),
            $declarationInfoFactory,
            $scanner,
            $reader,
            $parser,
        );
        $listeners->addListener(WatchedFileChangedEvent::class, $autoloadFiles->onWatchedFileChanged(...));
        $listeners->addListener(AutoloadMapRegeneratedEvent::class, $autoloadFiles->onAutoloadMapRegeneratedEvent(...));

        $composerMap = new ComposerMapBackend(
            $mapReader->current(),
            $parser,
            $reader,
            $declarationInfoFactory,
            $scanner,
        );
        $listeners->addListener(WatchedFileChangedEvent::class, $composerMap->onWatchedFileChanged(...));
        $listeners->addListener(AutoloadMapRegeneratedEvent::class, $composerMap->onAutoloadMapRegeneratedEvent(...));

        $disk = new CachingSymbolSource($composerMap, CacheFactory::inMemory());
        $listeners->addListener(WatchedFileChangedEvent::class, $disk->onFileEvent(...));
        $listeners->addListener(OpenDocumentClosedEvent::class, $disk->onFileEvent(...));
        $listeners->addListener(AutoloadMapRegeneratedEvent::class, $disk->onAutoloadMapRegeneratedEvent(...));

        // The built-in backend owns its own derived index of internal symbols, so
        // enumeration and prefix search draw on the same source and cannot disagree
        // about which names count as built-in (§4.2). The CachingSymbolSource
        // decorator caches its childrenOf lookups.
        $builtins = new CachingSymbolSource(new BuiltinBackend(), CacheFactory::inMemory());
        $listeners->addListener(WatchedFileChangedEvent::class, $builtins->onFileEvent(...));
        $listeners->addListener(OpenDocumentClosedEvent::class, $builtins->onFileEvent(...));
        $listeners->addListener(AutoloadMapRegeneratedEvent::class, $builtins->onAutoloadMapRegeneratedEvent(...));

        $source = new CompositeSymbolSource(
            $openDocuments,
            $autoloadFiles,
            $disk,
            $builtins,
        );

        return new self($source, $openDocuments, $dispatcher);
    }
}
