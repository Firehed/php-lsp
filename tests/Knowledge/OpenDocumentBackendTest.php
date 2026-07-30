<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Tests\BuildsClassInfoTrait;
use PHPUnit\Framework\TestCase;

/**
 * The open-document backend is the highest-precedence source (RFC 1 §5.3): lookup
 * is served from the class metadata the write path registers per document, while
 * enumeration and prefix search read the live symbol index. These prove each query
 * and that a document's registration is replaced on update and dropped on close.
 */
final class OpenDocumentBackendTest extends TestCase
{
    use BuildsClassInfoTrait;

    private SymbolIndex $index;
    private OpenDocumentBackend $backend;

    protected function setUp(): void
    {
        $this->index = new SymbolIndex();
        $this->backend = new OpenDocumentBackend($this->index);
    }

    public function testLookupClassLikeReturnsARegisteredClass(): void
    {
        $this->backend->updateDocument('file:///Widget.php', [self::classInfo('V\Widget')]);

        $info = $this->backend->lookupClassLike(self::className('V\Widget'));

        self::assertNotNull($info, 'a registered class must resolve');
        self::assertSame('V\Widget', $info->name->fqn, 'the registered class must be returned unchanged');
    }

    public function testLookupClassLikeReturnsNullForAnUnregisteredClass(): void
    {
        self::assertNull(
            $this->backend->lookupClassLike(self::className('V\Absent')),
            'a name no open document declares is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testUpdateDocumentReplacesThePriorClassesForThatUri(): void
    {
        $uri = 'file:///Doc.php';
        $this->backend->updateDocument($uri, [self::classInfo('V\Alpha')]);
        $this->backend->updateDocument($uri, [self::classInfo('V\Beta')]);

        self::assertNull(
            $this->backend->lookupClassLike(self::className('V\Alpha')),
            'the prior class must be dropped when the document is re-registered',
        );
        self::assertNotNull(
            $this->backend->lookupClassLike(self::className('V\Beta')),
            'the new class must be registered',
        );
    }

    public function testRemoveDocumentDropsItsClasses(): void
    {
        $uri = 'file:///Ephemeral.php';
        $this->backend->updateDocument($uri, [self::classInfo('V\Ephemeral')]);

        $this->backend->removeDocument($uri);

        self::assertNull(
            $this->backend->lookupClassLike(self::className('V\Ephemeral')),
            'closing a document must drop the classes it registered',
        );
    }

    public function testRemoveDocumentIsANoOpForAnUnknownUri(): void
    {
        $this->backend->removeDocument('file:///never-opened.php');

        self::assertNull(
            $this->backend->lookupClassLike(self::className('V\Nothing')),
            'removing a document that was never registered must not error',
        );
    }

    public function testSearchClassLikesFiltersByPrefixAndToClassLikeKindsOnly(): void
    {
        $this->addSymbol('User', 'App\User', SymbolKind::Class_);
        $this->addSymbol('Entity', 'App\Entity', SymbolKind::Class_);
        // A function whose name also begins with the prefix: it must not be returned,
        // because prefix search covers the class-like namespace only.
        $this->addSymbol('Userland', 'App\Userland', SymbolKind::Function_);

        $results = $this->backend->searchClassLikes('User');

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains('App\User', $fqns, 'a class-like matching the prefix must be found');
        self::assertNotContains('App\Entity', $fqns, 'a class-like not matching the prefix must be excluded');
        self::assertNotContains(
            'App\Userland',
            $fqns,
            'a function must be excluded even when its name matches the prefix',
        );
    }

    public function testChildrenOfEnumeratesTheOpenDocumentNamespace(): void
    {
        $this->addSymbol('User', 'App\User', SymbolKind::Class_);
        $this->addSymbol('Thing', 'App\Sub\Thing', SymbolKind::Class_);

        $contents = $this->backend->childrenOf(new NamespaceName('App'));

        $symbolFqns = array_map(
            static fn($symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
        self::assertContains('App\User', $symbolFqns, 'a symbol declared directly in the namespace must be listed');
        self::assertContains(
            'App\Sub',
            $contents->childNamespaces,
            'a namespace with a deeper declaration must be listed as a child',
        );
    }

    private function addSymbol(string $name, string $fqn, SymbolKind $kind): void
    {
        $this->index->add(new Symbol($name, $fqn, $kind, new Location('file:///' . $name . '.php', 0, 0, 0, 0)));
    }
}
