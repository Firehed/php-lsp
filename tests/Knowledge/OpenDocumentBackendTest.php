<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\TestCase;

/**
 * The open-document backend is the highest-precedence source (RFC 1 §5.3): the
 * lookup store, namespace enumeration and prefix search all answer from three
 * typed maps per document. These prove each query and that a document's
 * registration is replaced on update and dropped on close.
 */
final class OpenDocumentBackendTest extends TestCase
{
    use BuildsSymbolInfoTrait;
    use LooksUpBackendSymbolsTrait;

    private OpenDocumentBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new OpenDocumentBackend();
    }

    public function testLookupClassLikeReturnsARegisteredClass(): void
    {
        $this->writeClasses('file:///Widget.php', self::classInfo('V\Widget'));

        $info = self::classLikeIn($this->backend, 'V\Widget');

        self::assertNotNull($info, 'a registered class must resolve');
        self::assertSame(
            'V\Widget',
            $info->name->qualifiedName->fullyQualifiedName(),
            'the registered class must be returned unchanged',
        );
    }

    public function testLookupClassLikeIsCaseInsensitive(): void
    {
        $this->writeClasses('file:///Widget.php', self::classInfo('V\Widget'));

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
        $this->writeClasses($uri, self::classInfo('V\Alpha'));
        $this->writeClasses($uri, self::classInfo('V\Beta'));

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
        $this->writeClasses($uri, self::classInfo('V\Ephemeral'));

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
        $this->writeFunctions('file:///helpers.php', self::functionInfo('V\format'));

        $info = self::functionIn($this->backend, 'V\format');

        self::assertNotNull($info, 'a registered function must resolve');
        self::assertSame(
            'V\format',
            $info->name->qualifiedName->fullyQualifiedName(),
            'the registered function must be returned unchanged',
        );
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        $this->writeFunctions('file:///helpers.php', self::functionInfo('V\format'));

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

    public function testConstantLookupHonorsCaseRules(): void
    {
        $this->backend->updateDocument(
            'file:///consts.php',
            [],
            [self::constantInfo('V\LIMIT')],
            [],
        );

        self::assertNotNull(
            self::constantIn($this->backend, 'V\LIMIT'),
            'a registered constant must resolve for its kind',
        );
        self::assertNull(
            self::functionIn($this->backend, 'V\LIMIT'),
            'and must not answer for another symbol namespace',
        );
        self::assertNotNull(
            self::constantIn($this->backend, 'v\LIMIT'),
            'the namespace of a constant is still matched case-insensitively',
        );
        self::assertNull(
            self::constantIn($this->backend, 'V\limit'),
            'but its own name is not: constants are the one kind PHP matches case-sensitively',
        );
    }

    public function testFunctionAndClassLikeRegistrationsDoNotCollide(): void
    {
        $this->backend->updateDocument(
            'file:///Dual.php',
            [self::classInfo('V\Dual')],
            [],
            [self::functionInfo('V\Dual')],
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
        $this->writeFunctions($uri, self::functionInfo('V\alpha'));
        $this->writeFunctions($uri, self::functionInfo('V\beta'));

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
        $this->writeFunctions($uri, self::functionInfo('V\ephemeral'));

        $this->backend->removeDocument($uri);

        self::assertNull(
            self::functionIn($this->backend, 'V\ephemeral'),
            'closing a document must drop the functions it registered',
        );
    }

    public function testSearchClassLikeFiltersByPrefixAndToClassLikeKindsOnly(): void
    {
        $this->backend->updateDocument(
            'file:///doc.php',
            [
                self::classInfo('App\User'),
                self::classInfo('App\UserEnum'),
                self::classInfo('App\UserInterface'),
                self::classInfo('App\UserTrait'),
                self::classInfo('App\Entity'),
            ],
            [],
            [self::functionInfo('App\Userland')],
        );

        $results = $this->backend->searchClassLikes('User');

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains('App\User', $fqns, 'a class-like matching the prefix must be found');
        self::assertContains('App\UserEnum', $fqns, 'another class-like matching the prefix must be found');
        self::assertContains('App\UserInterface', $fqns, 'a third class-like matching the prefix must be found');
        self::assertContains('App\UserTrait', $fqns, 'a fourth class-like matching the prefix must be found');
        self::assertNotContains('App\Entity', $fqns, 'a class-like not matching the prefix must be excluded');
        self::assertNotContains(
            'App\Userland',
            $fqns,
            'a function must be excluded even when its name matches the prefix',
        );
    }

    public function testSearchFunctionFiltersByPrefixAndToFunctionKindOnly(): void
    {
        $this->backend->updateDocument(
            'file:///doc.php',
            [self::classInfo('App\Formatter')],
            [],
            [self::functionInfo('App\format')],
        );

        $results = $this->backend->searchFunctions('format');

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
        $this->backend->updateDocument(
            'file:///doc.php',
            [self::classInfo('App\Debugger')],
            [self::constantInfo('App\DEBUG')],
            [],
        );

        $results = $this->backend->searchConstants('D');

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
        $this->writeClasses('file:///User.php', self::classInfo('App\User'));
        $this->writeClasses('file:///Thing.php', self::classInfo('App\Sub\Thing'));

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

    private function writeClasses(string $uri, ClassInfo ...$classes): void
    {
        $this->backend->updateDocument($uri, array_values($classes), [], []);
    }

    private function writeFunctions(string $uri, FunctionInfo ...$functions): void
    {
        $this->backend->updateDocument($uri, [], [], array_values($functions));
    }
}
