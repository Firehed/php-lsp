<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Index\WorkspaceNamespaceSource;
use Firehed\PhpLsp\Resolution\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceNamespaceSource::class)]
#[CoversClass(CatalogSymbol::class)]
#[CoversClass(NamespaceContents::class)]
class WorkspaceNamespaceSourceTest extends TestCase
{
    private SymbolIndex $index;
    private WorkspaceNamespaceSource $source;

    protected function setUp(): void
    {
        $this->index = new SymbolIndex();
        $this->source = new WorkspaceNamespaceSource($this->index);
    }

    public function testSymbolsAreFiledUnderTheirNamespace(): void
    {
        $this->add('Thing', 'App\Thing', SymbolKind::Class_);
        $this->add('helper', 'App\helper', SymbolKind::Function_);

        $contents = $this->source->childrenOf('App');

        self::assertSame(
            ['App\Thing', 'App\helper'],
            self::fqns($contents),
            'Symbols declared in a namespace are its contents',
        );
    }

    public function testSymbolKindsMapToNameKinds(): void
    {
        $this->add('Thing', 'App\Thing', SymbolKind::Class_);
        $this->add('Contract', 'App\Contract', SymbolKind::Interface_);
        $this->add('Reusable', 'App\Reusable', SymbolKind::Trait_);
        $this->add('Status', 'App\Status', SymbolKind::Enum_);
        $this->add('helper', 'App\helper', SymbolKind::Function_);
        $this->add('LIMIT', 'App\LIMIT', SymbolKind::Constant);

        $kinds = [];
        foreach ($this->source->childrenOf('App')->symbols as $symbol) {
            $kinds[$symbol->shortName()] = $symbol->kind;
        }

        self::assertSame(
            [
                'Thing' => NameKind::ClassLike,
                'Contract' => NameKind::ClassLike,
                'Reusable' => NameKind::ClassLike,
                'Status' => NameKind::ClassLike,
                'helper' => NameKind::Function_,
                'LIMIT' => NameKind::Constant,
            ],
            $kinds,
            'Every class-like collapses to one kind; functions and constants keep their own',
        );
    }

    public function testMembersAreNotSymbolsOfANamespace(): void
    {
        $this->add('Thing', 'App\Thing', SymbolKind::Class_);
        $this->add('doIt', 'App\Thing::doIt', SymbolKind::Method);
        $this->add('name', 'App\Thing::name', SymbolKind::Property);

        self::assertSame(
            ['App\Thing'],
            self::fqns($this->source->childrenOf('App')),
            'Members belong to a class, not to a namespace, and are never referenced by name',
        );
    }

    public function testChildNamespacesAreDerivedFromSymbols(): void
    {
        $this->add('Repository', 'App\Model\User\Repository', SymbolKind::Class_);

        self::assertSame(
            ['App'],
            $this->source->childrenOf('')->childNamespaces,
            'A deeply nested symbol makes its root a child of the global namespace',
        );
        self::assertSame(
            ['App\Model'],
            $this->source->childrenOf('App')->childNamespaces,
            'Intermediate namespaces exist even with no symbols of their own',
        );
        self::assertSame(
            [],
            $this->source->childrenOf('App')->symbols,
            'An intermediate namespace holds no symbols itself',
        );
    }

    public function testSymbolsInUnrelatedNamespacesAreIgnored(): void
    {
        $this->add('Thing', 'App\Thing', SymbolKind::Class_);
        $this->add('Other', 'Vendor\Package\Other', SymbolKind::Class_);

        $contents = $this->source->childrenOf('App');

        self::assertSame(
            ['App\Thing'],
            self::fqns($contents),
            'A symbol outside the namespace is neither its content nor below it',
        );
        self::assertSame(
            [],
            $contents->childNamespaces,
            'Nor does it imply a child namespace',
        );
    }

    public function testGlobalNamespaceSymbols(): void
    {
        $this->add('globalHelper', 'globalHelper', SymbolKind::Function_);

        self::assertSame(
            ['globalHelper'],
            self::fqns($this->source->childrenOf('')),
            'A symbol with no namespace belongs to the global namespace',
        );
    }

    public function testNamespaceMatchingIsCaseInsensitive(): void
    {
        $this->add('Thing', 'App\Thing', SymbolKind::Class_);

        self::assertSame(
            ['App\Thing'],
            self::fqns($this->source->childrenOf('app')),
            'Namespaces are case-insensitive in PHP',
        );
    }

    public function testReflectsLaterEdits(): void
    {
        $this->add('Thing', 'App\Thing', SymbolKind::Class_);
        self::assertSame(['App\Thing'], self::fqns($this->source->childrenOf('App')));

        $this->index->clearByUri('file:///App/Thing.php');

        self::assertSame(
            [],
            self::fqns($this->source->childrenOf('App')),
            'The workspace changes as documents are edited, so its contents cannot be cached',
        );
    }

    private function add(string $name, string $fqn, SymbolKind $kind): void
    {
        $this->index->add(new Symbol(
            name: $name,
            fullyQualifiedName: $fqn,
            kind: $kind,
            location: new Location('file:///' . str_replace('\\', '/', $fqn) . '.php', 0, 0, 0, 1),
        ));
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
