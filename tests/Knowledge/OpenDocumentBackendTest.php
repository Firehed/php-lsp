<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\TestCase;

/**
 * The open-document backend is the highest-precedence source (RFC 1 §5.3): lookup
 * is served from the class metadata the write path registers per document, while
 * enumeration and prefix search read the live symbol index. These prove each query
 * and that a document's registration is replaced on update and dropped on close.
 */
final class OpenDocumentBackendTest extends TestCase
{
    use BuildsSymbolInfoTrait;
    use LooksUpBackendSymbolsTrait;

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

        $info = self::classLikeIn($this->backend, 'V\Widget');

        self::assertNotNull($info, 'a registered class must resolve');
        self::assertSame('V\Widget', $info->name->fqn, 'the registered class must be returned unchanged');
    }

    public function testLookupClassLikeReturnsNullForAnUnregisteredClass(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend, 'V\Absent'),
            'a name no open document declares is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testUpdateDocumentReplacesThePriorClassesForThatUri(): void
    {
        $uri = 'file:///Doc.php';
        $this->backend->updateDocument($uri, [self::classInfo('V\Alpha')]);
        $this->backend->updateDocument($uri, [self::classInfo('V\Beta')]);

        self::assertNull(
            self::classLikeIn($this->backend, 'V\Alpha'),
            'the prior class must be dropped when the document is re-registered',
        );
        self::assertNotNull(
            self::classLikeIn($this->backend, 'V\Beta'),
            'the new class must be registered',
        );
    }

    public function testRemoveDocumentDropsItsClasses(): void
    {
        $uri = 'file:///Ephemeral.php';
        $this->backend->updateDocument($uri, [self::classInfo('V\Ephemeral')]);

        $this->backend->removeDocument($uri);

        self::assertNull(
            self::classLikeIn($this->backend, 'V\Ephemeral'),
            'closing a document must drop the classes it registered',
        );
    }

    public function testRemoveDocumentIsANoOpForAnUnknownUri(): void
    {
        $this->backend->removeDocument('file:///never-opened.php');

        self::assertNull(
            self::classLikeIn($this->backend, 'V\Nothing'),
            'removing a document that was never registered must not error',
        );
    }

    public function testLookupFunctionReturnsARegisteredFunction(): void
    {
        $this->backend->updateDocument('file:///helpers.php', [], ['V\format' => self::functionInfo('format')]);

        $info = self::functionIn($this->backend, 'V\format');

        self::assertNotNull($info, 'a registered function must resolve');
        self::assertSame('format', $info->name, 'the registered function must be returned unchanged');
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        $this->backend->updateDocument('file:///helpers.php', [], ['V\format' => self::functionInfo('format')]);

        self::assertNotNull(
            self::functionIn($this->backend, 'V\FORMAT'),
            'PHP matches function names case-insensitively',
        );
    }

    public function testLookupFunctionReturnsNullForAnUnregisteredFunction(): void
    {
        self::assertNull(
            self::functionIn($this->backend, 'V\absent'),
            'a name no open document declares is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testFunctionAndClassLikeRegistrationsDoNotCollide(): void
    {
        $this->backend->updateDocument(
            'file:///Dual.php',
            [self::classInfo('V\Dual')],
            ['V\Dual' => self::functionInfo('Dual')],
        );

        self::assertNotNull(
            self::classLikeIn($this->backend, 'V\Dual'),
            'the class-like must resolve',
        );
        self::assertNotNull(
            self::functionIn($this->backend, 'V\Dual'),
            'a function sharing the name must resolve too: the symbol namespaces are independent',
        );
    }

    public function testUpdateDocumentReplacesThePriorFunctionsForThatUri(): void
    {
        $uri = 'file:///helpers.php';
        $this->backend->updateDocument($uri, [], ['V\alpha' => self::functionInfo('alpha')]);
        $this->backend->updateDocument($uri, [], ['V\beta' => self::functionInfo('beta')]);

        self::assertNull(
            self::functionIn($this->backend, 'V\alpha'),
            'the prior function must be dropped when the document is re-registered',
        );
        self::assertNotNull(
            self::functionIn($this->backend, 'V\beta'),
            'the new function must be registered',
        );
    }

    public function testRemoveDocumentDropsItsFunctions(): void
    {
        $uri = 'file:///helpers.php';
        $this->backend->updateDocument($uri, [], ['V\ephemeral' => self::functionInfo('ephemeral')]);

        $this->backend->removeDocument($uri);

        self::assertNull(
            self::functionIn($this->backend, 'V\ephemeral'),
            'closing a document must drop the functions it registered',
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
