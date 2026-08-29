<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Domain\Location;
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
        $this->backend->updateDocument('file:///Widget.php', self::declaredClass('V\Widget'));

        $info = self::classLikeIn($this->backend, 'V\Widget');

        self::assertNotNull($info, 'a registered class must resolve');
        self::assertSame('V\Widget', $info->name->fqn, 'the registered class must be returned unchanged');
    }

    public function testLookupClassLikeIsCaseInsensitive(): void
    {
        $this->backend->updateDocument('file:///Widget.php', self::declaredClass('V\Widget'));

        self::assertNotNull(
            self::classLikeIn($this->backend, 'v\WIDGET'),
            'PHP matches class-like names case-insensitively',
        );
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
        $this->backend->updateDocument($uri, self::declaredClass('V\Alpha'));
        $this->backend->updateDocument($uri, self::declaredClass('V\Beta'));

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
        $this->backend->updateDocument($uri, self::declaredClass('V\Ephemeral'));

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
        $this->backend->updateDocument('file:///helpers.php', self::declaredFunction('V\format'));

        $info = self::functionIn($this->backend, 'V\format');

        self::assertNotNull($info, 'a registered function must resolve');
        self::assertSame('format', $info->name, 'the registered function must be returned unchanged');
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        $this->backend->updateDocument('file:///helpers.php', self::declaredFunction('V\format'));

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

    public function testRegistrationCarriesAKindItKnowsNothingAbout(): void
    {
        // The point of the kind-parameterized write path: a kind whose metadata type
        // this backend has never heard of round-trips, so adding one is a change to
        // the info factories alone (Plan 0002 §5.6).
        $info = new class implements SymbolInfo {
        };
        $name = QualifiedName::fromFullyQualified('V\LIMIT');

        $this->backend->updateDocument(
            'file:///consts.php',
            new DeclaredSymbol($name, NameKind::Constant, $info),
        );

        self::assertSame(
            $info,
            $this->backend->lookup($name, NameKind::Constant),
            'a registered symbol of any kind must resolve for that kind',
        );
        self::assertNull(
            $this->backend->lookup($name, NameKind::Function_),
            'and must not answer for another symbol namespace',
        );
        self::assertSame(
            $info,
            $this->backend->lookup(QualifiedName::fromFullyQualified('v\LIMIT'), NameKind::Constant),
            'the namespace of a constant is still matched case-insensitively',
        );
        self::assertNull(
            $this->backend->lookup(QualifiedName::fromFullyQualified('V\limit'), NameKind::Constant),
            'but its own name is not: constants are the one kind PHP matches case-sensitively',
        );
    }

    public function testFunctionAndClassLikeRegistrationsDoNotCollide(): void
    {
        $this->backend->updateDocument(
            'file:///Dual.php',
            self::declaredClass('V\Dual'),
            self::declaredFunction('V\Dual'),
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
        $this->backend->updateDocument($uri, self::declaredFunction('V\alpha'));
        $this->backend->updateDocument($uri, self::declaredFunction('V\beta'));

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
        $this->backend->updateDocument($uri, self::declaredFunction('V\ephemeral'));

        $this->backend->removeDocument($uri);

        self::assertNull(
            self::functionIn($this->backend, 'V\ephemeral'),
            'closing a document must drop the functions it registered',
        );
    }

    public function testSearchClassLikeFiltersByPrefixAndToClassLikeKindsOnly(): void
    {
        $this->addSymbol('User', 'App\User', SymbolKind::Class_);
        $this->addSymbol('UserEnum', 'App\UserEnum', SymbolKind::Enum_);
        $this->addSymbol('UserInterface', 'App\UserInterface', SymbolKind::Interface_);
        $this->addSymbol('UserTrait', 'App\UserTrait', SymbolKind::Trait_);
        $this->addSymbol('Entity', 'App\Entity', SymbolKind::Class_);
        $this->addSymbol('Userland', 'App\Userland', SymbolKind::Function_);

        $results = $this->backend->search('User', NameKind::ClassLike);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains('App\User', $fqns, 'a class must be found');
        self::assertContains('App\UserEnum', $fqns, 'an enum must be found');
        self::assertContains('App\UserInterface', $fqns, 'an interface must be found');
        self::assertContains('App\UserTrait', $fqns, 'a trait must be found');
        self::assertNotContains('App\Entity', $fqns, 'a class-like not matching the prefix must be excluded');
        self::assertNotContains(
            'App\Userland',
            $fqns,
            'a function must be excluded even when its name matches the prefix',
        );
    }

    public function testSearchFunctionFiltersByPrefixAndToFunctionKindOnly(): void
    {
        $this->addSymbol('format', 'App\format', SymbolKind::Function_);
        $this->addSymbol('Formatter', 'App\Formatter', SymbolKind::Class_);

        $results = $this->backend->search('format', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains('App\format', $fqns, 'a function matching the prefix must be found');
        self::assertNotContains(
            'App\Formatter',
            $fqns,
            'a class-like must be excluded from a function search',
        );
    }

    public function testSearchConstantFiltersByPrefixAndToConstantKindOnly(): void
    {
        $this->addSymbol('DEBUG', 'App\DEBUG', SymbolKind::Constant);
        $this->addSymbol('Debugger', 'App\Debugger', SymbolKind::Class_);

        $results = $this->backend->search('D', NameKind::Constant);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains('App\DEBUG', $fqns, 'a constant matching the prefix must be found');
        self::assertNotContains(
            'App\Debugger',
            $fqns,
            'a class-like must be excluded from a constant search',
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
