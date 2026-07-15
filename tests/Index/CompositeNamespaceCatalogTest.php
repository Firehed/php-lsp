<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\CompositeNamespaceCatalog;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Index\WorkspaceNamespaceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeNamespaceCatalog::class)]
#[CoversClass(NamespaceContents::class)]
class CompositeNamespaceCatalogTest extends TestCase
{
    private const FIXTURES_ROOT = __DIR__ . '/../Fixtures';

    private SymbolIndex $index;
    private CompositeNamespaceCatalog $catalog;

    protected function setUp(): void
    {
        $this->index = new SymbolIndex();
        $this->catalog = new CompositeNamespaceCatalog([
            new WorkspaceNamespaceSource($this->index),
            new ComposerNamespaceSource(ComposerAutoloadMap::fromProjectRoot(self::FIXTURES_ROOT)),
            new ReflectionNamespaceSource(),
        ]);
    }

    public function testEverySourceContributes(): void
    {
        $this->index->add(new Symbol(
            name: 'Workspace',
            fullyQualifiedName: 'Fixtures\Workspace',
            kind: SymbolKind::Class_,
            location: new Location('file:///Workspace.php', 0, 0, 0, 1),
        ));

        self::assertContains(
            'Fixtures\Workspace',
            self::fqns($this->catalog->childrenOf('Fixtures')),
            'A workspace symbol is discoverable',
        );
        self::assertContains(
            'Psr\Http\Message\RequestInterface',
            self::fqns($this->catalog->childrenOf('Psr\Http\Message')),
            'A vendor symbol is discoverable',
        );
        self::assertContains(
            'SessionHandlerInterface',
            self::fqns($this->catalog->childrenOf('')),
            'A built-in symbol is discoverable',
        );
    }

    public function testASymbolReportedByTwoSourcesIsOfferedOnce(): void
    {
        // Fixtures\Domain\User is both a file under the PSR-4 prefix and, once
        // opened or scanned, a workspace symbol.
        $this->index->add(new Symbol(
            name: 'User',
            fullyQualifiedName: 'Fixtures\Domain\User',
            kind: SymbolKind::Class_,
            location: new Location('file:///User.php', 0, 0, 0, 1),
        ));

        $users = array_filter(
            self::fqns($this->catalog->childrenOf('Fixtures\Domain')),
            static fn(string $fqn): bool => $fqn === 'Fixtures\Domain\User',
        );

        self::assertCount(1, $users, 'Sources overlap by design; a symbol must not be offered twice');
    }

    public function testChildNamespacesAreMergedAcrossSources(): void
    {
        $children = $this->catalog->childrenOf('');

        self::assertContains('Fixtures', $children->childNamespaces, 'From the autoload maps');
        self::assertContains('Random', $children->childNamespaces, 'From the built-ins');
    }

    public function testWorkspaceEditsAreVisibleImmediately(): void
    {
        self::assertNotContains(
            'Fixtures\Domain\Added',
            self::fqns($this->catalog->childrenOf('Fixtures\Domain')),
            'Not yet declared',
        );

        $this->index->add(new Symbol(
            name: 'Added',
            fullyQualifiedName: 'Fixtures\Domain\Added',
            kind: SymbolKind::Class_,
            location: new Location('file:///Added.php', 0, 0, 0, 1),
        ));

        self::assertContains(
            'Fixtures\Domain\Added',
            self::fqns($this->catalog->childrenOf('Fixtures\Domain')),
            'A namespace already looked at must not be served stale after an edit',
        );
    }

    /**
     * @return list<string>
     */
    private static function fqns(NamespaceContents $contents): array
    {
        return array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
    }
}
