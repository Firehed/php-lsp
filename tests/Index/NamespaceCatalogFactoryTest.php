<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\NamespaceCatalogFactory;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamespaceCatalogFactory::class)]
class NamespaceCatalogFactoryTest extends TestCase
{
    public function testComposesTheWorkspaceComposerAndReflectionSources(): void
    {
        $index = new SymbolIndex();
        $index->add(new Symbol(
            'Widget',
            'Workspace\Widget',
            SymbolKind::Class_,
            new Location('file:///Widget.php', 0, 0, 0, 1),
        ));

        $catalog = NamespaceCatalogFactory::forProject($index, __DIR__ . '/../Fixtures');
        $globalChildren = $catalog->childrenOf('')->childNamespaces;

        self::assertContains('Workspace', $globalChildren, 'The workspace index source contributes');
        self::assertContains('Psr', $globalChildren, 'The Composer autoload source contributes');
        self::assertContains('Random', $globalChildren, 'The reflection source contributes the built-in namespaces');
    }

    public function testTheWorkspaceSourceIsNotCached(): void
    {
        $index = new SymbolIndex();
        $catalog = NamespaceCatalogFactory::forProject($index, __DIR__ . '/../Fixtures');

        self::assertNotContains('Late', $catalog->childrenOf('')->childNamespaces, 'Not declared yet');

        $index->add(new Symbol(
            'Added',
            'Late\Added',
            SymbolKind::Class_,
            new Location('file:///Added.php', 0, 0, 0, 1),
        ));

        self::assertContains(
            'Late',
            $catalog->childrenOf('')->childNamespaces,
            'A workspace edit is visible immediately; the workspace source must not be behind the cache',
        );
    }
}
