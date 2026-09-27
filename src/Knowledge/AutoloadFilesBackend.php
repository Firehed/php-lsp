<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Events\WatchedFileChangedEvent;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * The {@see SymbolSourceInterface} over Composer's `autoload.files` set — the one
 * place Composer addresses a declaration by no name at all. PHP `require`s each
 * entry wholesale at bootstrap, so the only route to a name declared there is to
 * parse the set and derive a name -> declaration map (Plan 0002 §3).
 *
 * The index is built eagerly at construction, and rebuilt when a file in the set
 * changes on disk or when a regenerated autoload map arrives as an event
 * (RFC 1 §5.2, §5.3). Composer requires the entries in order, so the first
 * entry to declare a name is the one that takes effect; a later guarded
 * redeclaration of the same name never runs. That precedence is what the shared
 * {@see DeclaredSymbolStoreTrait} store gives every read of the index: lookup,
 * `childrenOf` and `search` all resolve the same declaration for any shared name.
 *
 * Every symbol namespace is covered, because once an entry is parsed the three
 * kinds cost the same walk — and a scan narrowed to functions and constants
 * would leave a class-like declared in the set reachable at runtime but invisible
 * here (RFC 1 §4.2).
 */
final class AutoloadFilesBackend implements SymbolSourceInterface
{
    use DeclaredSymbolStoreTrait;
    use LooksUpByKindTrait;

    private ComposerAutoloadMap $map;

    public function __construct(
        ComposerAutoloadMap $initialMap,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly DeclarationScanner $scanner,
        private readonly SourceFileReader $reader,
        private readonly SyntaxSourceInterface $parser,
    ) {
        $this->map = $initialMap;
        $this->buildIndex();
    }

    public function onAutoloadMapRegeneratedEvent(AutoloadMapRegeneratedEvent $event): void
    {
        $this->map = $event->map;
        $this->buildIndex();
    }

    /**
     * Re-derives the index when a file in the entry set changed on disk (RFC 1
     * §5.2, §5.3). A change to any other path costs nothing.
     */
    public function onWatchedFileChanged(WatchedFileChangedEvent $event): void
    {
        if (!in_array(FileUri::toPath($event->uri), $this->map->autoloadFiles(), true)) {
            return;
        }

        $this->buildIndex();
    }

    private function buildIndex(): void
    {
        $this->clearAllSymbols();
        foreach ($this->map->autoloadFiles() as $path) {
            $declarations = $this->scanner->scanFile($path, $this->reader, $this->parser);
            $this->setSymbolsFor(FileUri::fromPath($path), ...$this->infoFactory->allIn($declarations, $path));
        }
    }
}
