<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Resolution\NameKind;

/**
 * Locates a declaration in Composer's `autoload.files` set, by deriving the
 * name -> file map that set does not have.
 *
 * Every other autoload route is arithmetic on the name: PSR-4, PSR-0 and the
 * classmap all address a class-like by name, so {@see ComposerSymbolLocator} answers
 * without reading anything. The `files` set cannot — PHP `require`s each entry
 * wholesale at bootstrap and nothing keys them by name — so this is the one place
 * the model must scan rather than resolve (Plan 0002 §3).
 *
 * The index is built eagerly, and covers all three symbol namespaces: once an entry
 * is parsed every kind costs the same walk, so indexing only functions and constants
 * would leave a class-like declared there reachable at runtime but invisible here,
 * for no saving. The cost is bounded because the set is explicit and usually tiny.
 */
final class AutoloadFilesLocator implements SymbolLocator, Invalidatable
{
    /**
     * Kind => normalized name => declaring file.
     *
     * @var array<string, array<string, string>>
     */
    private array $index;

    public function __construct(
        private readonly ComposerAutoloadMap $map,
        private readonly ParserService $parser,
        private readonly DeclarationScanner $scanner,
    ) {
        $this->index = $this->buildIndex();
    }

    /**
     * Re-derives the index when a file in the set changed on disk. A change
     * outside the set cannot affect it, so it costs nothing (RFC 1 §5.2, §5.3).
     */
    public function invalidate(string $uri): void
    {
        if (!in_array(FileUri::toPath($uri), $this->map->autoloadFiles(), true)) {
            return;
        }

        $this->index = $this->buildIndex();
    }

    public function locate(QualifiedName $name, NameKind $kind): ?string
    {
        $declarations = $this->index[$kind->name];
        $key = self::key($name, $kind);

        return array_key_exists($key, $declarations) ? $declarations[$key] : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function buildIndex(): array
    {
        $index = [];
        foreach (NameKind::cases() as $kind) {
            $index[$kind->name] = [];
        }

        foreach ($this->map->autoloadFiles() as $path) {
            $ast = $this->parser->parseFile($path);
            if ($ast === null) {
                continue;
            }

            $declarations = $this->scanner->scan($ast);
            self::record($index, NameKind::ClassLike, $declarations->classLikes, $path);
            self::record($index, NameKind::Function_, $declarations->functions, $path);
            self::record($index, NameKind::Constant, $declarations->constants, $path);
        }

        return $index;
    }

    /**
     * PHP matches class-like and function names case-insensitively and constant
     * names exactly, so the key a kind is stored and looked up under follows the
     * rule for that kind rather than one rule for all three.
     */
    private static function key(QualifiedName $name, NameKind $kind): string
    {
        $fqn = $name->fullyQualifiedName();

        return $kind->isCaseSensitive() ? $fqn : strtolower($fqn);
    }

    /**
     * @param array<string, array<string, string>> $index
     * @param list<QualifiedName> $names
     */
    private static function record(array &$index, NameKind $kind, array $names, string $path): void
    {
        foreach ($names as $name) {
            $key = self::key($name, $kind);

            // Composer requires the entries in order, so the first declaration of a
            // name is the one that takes effect; a later guarded redeclaration of
            // the same name never runs.
            if (!array_key_exists($key, $index[$kind->name])) {
                $index[$kind->name][$key] = $path;
            }
        }
    }
}
