<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespacePath;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\Declaration;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSource;
use PhpParser\Node;

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
 *
 * The same index answers both reads of it — {@see locate()} for a known name and
 * {@see childrenOf()} for a namespace's contents. Enumerating it is not optional:
 * these files sit outside every PSR-4 and PSR-0 prefix, so a directory listing
 * cannot see them, and a name that resolved on hover while being invisible to
 * completion is exactly the lookup/enumeration split RFC 1 §4.2 forbids. The kind
 * reported is the declaration's own, not the coarse guess a directory listing makes.
 */
final class AutoloadFilesLocator implements SymbolLocator, NamespaceCatalog, PrefixSearchable, Invalidatable
{
    /**
     * Every name the set declares, as the declaration spells it: the index is keyed
     * for lookup under PHP's per-kind case rules, which loses the casing a
     * completion item has to insert.
     *
     * @var list<CatalogSymbol>
     */
    private array $declarations;

    /**
     * Declarations grouped by kind name, so prefix search filters only the
     * matching kind without comparing enum values (which would branch on kind).
     *
     * @var array<string, list<CatalogSymbol>>
     */
    private array $declarationsByKind;

    /**
     * Kind => normalized name => declaring file.
     *
     * @var array<string, array<string, string>>
     */
    private array $index;

    /**
     * Built on first enumeration rather than alongside the index: a project that
     * never navigates a namespace never pays for the grouping.
     *
     * @var array<string, NamespaceContents>|null Lowercase namespace -> contents
     */
    private ?array $namespaces = null;

    public function __construct(
        private readonly ComposerAutoloadMap $map,
        private readonly SyntaxSource $parser,
        private readonly SourceFileReader $reader,
        private readonly DeclarationScanner $scanner,
    ) {
        $this->buildIndex();
    }

    public function childrenOf(string $namespace): NamespaceContents
    {
        $this->namespaces ??= NamespaceContents::indexByNamespace($this->declarations);

        return $this->namespaces[NamespacePath::normalize($namespace)] ?? new NamespaceContents();
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

        $this->buildIndex();
    }

    /**
     * @return list<Symbol>
     */
    public function searchByPrefix(string $prefix, NameKind $kind): array
    {
        return PrefixSearch::filter(
            $this->declarationsByKind[$kind->name],
            $prefix,
            $kind,
            function (CatalogSymbol $symbol) use ($kind): Location {
                $filePath = $this->index[$kind->name][$kind->normalize(
                    QualifiedName::fromFullyQualified($symbol->fullyQualifiedName),
                )];
                return new Location(FileUri::fromPath($filePath), 0, 0, 0, 0);
            },
        );
    }

    public function locate(QualifiedName $name, NameKind $kind): ?string
    {
        $declarations = $this->index[$kind->name];
        $key = $kind->normalize($name);

        return array_key_exists($key, $declarations) ? $declarations[$key] : null;
    }

    private function buildIndex(): void
    {
        $this->index = [];
        $this->declarationsByKind = [];
        foreach (NameKind::cases() as $kind) {
            $this->index[$kind->name] = [];
            $this->declarationsByKind[$kind->name] = [];
        }
        $this->declarations = [];
        $this->namespaces = null;

        foreach ($this->map->autoloadFiles() as $path) {
            $document = $this->reader->read($path);
            if ($document === null) {
                continue;
            }

            $declarations = $this->scanner->scan($this->parser->parse($document));
            $this->record(NameKind::ClassLike, $declarations->classLikes, $path);
            $this->record(NameKind::Function_, $declarations->functions, $path);
            $this->record(NameKind::Constant, $declarations->constants, $path);
        }
    }

    /**
     * @param list<Declaration<Node>> $declarations
     */
    private function record(NameKind $kind, array $declarations, string $path): void
    {
        foreach ($declarations as $declaration) {
            $name = $declaration->name;
            $key = $kind->normalize($name);

            // Composer requires the entries in order, so the first declaration of a
            // name is the one that takes effect; a later guarded redeclaration of
            // the same name never runs. Enumeration reports the winner for the same
            // reason, and so a name declared twice is not offered twice.
            if (array_key_exists($key, $this->index[$kind->name])) {
                continue;
            }

            $this->index[$kind->name][$key] = $path;
            $symbol = new CatalogSymbol($name->fullyQualifiedName(), $kind);
            $this->declarations[] = $symbol;
            $this->declarationsByKind[$kind->name][] = $symbol;
        }
    }
}
