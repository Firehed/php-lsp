<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * The {@see SymbolSourceInterface} over Composer's `autoload.files` set — the one
 * place Composer addresses a declaration by no name at all. PHP `require`s each
 * entry wholesale at bootstrap, so the only route to a name declared there is to
 * parse the set and derive a name -> declaration map (Plan 0002 §3).
 *
 * The index is built eagerly at construction, and rebuilt when a file in the set
 * changes on disk (RFC 1 §5.2, §5.3). The map itself is read through
 * {@see ComposerAutoloadMapReader}, so a regenerated `autoload_files.php` under
 * `vendor/composer/` reaches the entry list on the next invalidation and the
 * derived index rebuilds against the new set. Composer requires the entries in
 * order, so the first entry to declare a name is the one that takes effect; a
 * later guarded redeclaration of the same name never runs. That precedence is
 * what the shared {@see DeclaredSymbolStoreTrait} store gives every read of the
 * index: lookup, `childrenOf` and `search` all resolve the same declaration for
 * any shared name.
 *
 * Every symbol namespace is covered, because once an entry is parsed the three
 * kinds cost the same walk — and a scan narrowed to functions and constants
 * would leave a class-like declared in the set reachable at runtime but invisible
 * here (RFC 1 §4.2).
 */
final class AutoloadFilesBackend implements SymbolSourceInterface, InvalidatableInterface
{
    use DeclaredSymbolStoreTrait;
    use LooksUpByKindTrait;

    private ?ComposerAutoloadMap $mapAtBuild = null;

    public function __construct(
        private readonly ComposerAutoloadMapReader $mapReader,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly DeclarationScanner $scanner,
        private readonly SourceFileReader $reader,
        private readonly SyntaxSourceInterface $parser,
    ) {
        $this->buildIndex($this->mapReader->current());
    }

    /**
     * Re-derives the index when a file in the set changed on disk, or when the
     * autoload map itself was regenerated (a `composer install`, seen here as a
     * change of the map instance {@see ComposerAutoloadMapReader::current()}
     * returns). A change to neither costs nothing (RFC 1 §5.2, §5.3).
     */
    public function invalidate(string $uri): void
    {
        $map = $this->mapReader->current();
        if ($map === $this->mapAtBuild && !in_array(FileUri::toPath($uri), $map->autoloadFiles(), true)) {
            return;
        }

        $this->buildIndex($map);
    }

    private function buildIndex(ComposerAutoloadMap $map): void
    {
        foreach (array_keys($this->symbolsBySource) as $source) {
            $this->removeSymbolsFor($source);
        }
        foreach ($map->autoloadFiles() as $path) {
            $declarations = $this->scanner->scanFile($path, $this->reader, $this->parser);
            $this->setSymbolsFor(FileUri::fromPath($path), ...$this->infoFactory->allIn($declarations, $path));
        }
        $this->mapAtBuild = $map;
    }
}
