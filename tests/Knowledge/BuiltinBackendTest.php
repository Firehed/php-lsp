<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\PrefixSearchable;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\BuiltinBackend;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\ReflectionSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\SymbolCache;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use PHPUnit\Framework\TestCase;

/**
 * The built-in backend is the lowest-precedence source (RFC 1 §5.3): it reflects the
 * symbols the server runtime has loaded. These prove lookup via reflection, its
 * caching, absence for an unknown name, the empty prefix search, and that
 * enumeration forwards to the reflection catalog.
 */
final class BuiltinBackendTest extends TestCase
{
    use LooksUpBackendSymbolsTrait;

    private function backend(NamespaceCatalog $namespaces): BuiltinBackend
    {
        return new BuiltinBackend(
            new ReflectionSymbolInfoFactory(new DefaultClassInfoFactory()),
            $namespaces,
            new SymbolCache(CacheFactory::inMemory()),
        );
    }

    private function backendWithSearch(): BuiltinBackend
    {
        $reflectionSource = new ReflectionNamespaceSource();
        return new BuiltinBackend(
            new ReflectionSymbolInfoFactory(new DefaultClassInfoFactory()),
            $reflectionSource,
            new SymbolCache(CacheFactory::inMemory()),
            $reflectionSource,
        );
    }

    public function testLookupClassLikeReflectsABuiltinClass(): void
    {
        $info = self::classLikeIn($this->backend(self::createStub(NamespaceCatalog::class)), \ArrayObject::class);

        self::assertNotNull($info, 'a loaded built-in class must resolve through reflection');
        self::assertSame('ArrayObject', $info->name->fqn, 'the reflected class must be returned');
    }

    public function testLookupClassLikeReturnsNullForAnUnknownClass(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend(self::createStub(NamespaceCatalog::class)), 'No\Such\Builtin'),
            'a name reflection cannot load is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupClassLikeCachesAResolvedClass(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalog::class));

        $first = self::classLikeIn($backend, \ArrayObject::class);
        $second = self::classLikeIn($backend, \ArrayObject::class);

        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');
        self::assertSame($first, $second, 'a second lookup must return the cached instance, not re-reflect');
    }

    public function testLookupFunctionReflectsABuiltinFunction(): void
    {
        $info = self::functionIn($this->backend(self::createStub(NamespaceCatalog::class)), 'str_contains');

        self::assertNotNull($info, 'a built-in function must resolve through reflection');
        self::assertSame('str_contains', $info->name);
        self::assertCount(2, $info->parameters, 'the reflected signature must be carried');
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        self::assertNotNull(
            self::functionIn($this->backend(self::createStub(NamespaceCatalog::class)), 'STR_CONTAINS'),
            'PHP matches function names case-insensitively',
        );
    }

    public function testLookupFunctionIgnoresFunctionsOnlyTheServerHasLoaded(): void
    {
        // The server is itself a PHP program, so reflection can see every function
        // its own dependencies declare. Those are not the project's, and answering
        // for one would report a function the user's code cannot call. The backend
        // enumerates only internal functions (BuiltinFunctionParityTest), so lookup
        // must agree or a name resolves on hover yet never appears in completion
        // (RFC 1 §4.2).
        require_once dirname(__DIR__) . '/Domain/Fixtures/documented_function.php';

        self::assertNull(
            self::functionIn($this->backend(self::createStub(NamespaceCatalog::class)), 'testDocumentedFunction'),
            'a userland function loaded in the server process is not a built-in',
        );
    }

    public function testLookupFunctionReturnsNullForAnUnknownFunction(): void
    {
        self::assertNull(
            self::functionIn($this->backend(self::createStub(NamespaceCatalog::class)), 'no_such_builtin'),
            'a name reflection cannot load is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupFunctionCachesAResolvedFunction(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalog::class));

        $first = self::functionIn($backend, 'str_contains');
        $second = self::functionIn($backend, 'str_contains');

        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');
        self::assertSame($first, $second, 'a second lookup must return the cached instance, not re-reflect');
    }

    public function testFunctionAndClassLikeCachesDoNotCollide(): void
    {
        // PHP's three symbol namespaces are independent, so one name can be both a
        // class and a function. A cache keyed on the name alone would serve a
        // ClassInfo to a function lookup.
        $backend = $this->backend(self::createStub(NamespaceCatalog::class));

        self::classLikeIn($backend, \ArrayObject::class);

        self::assertNull(
            self::functionIn($backend, 'ArrayObject'),
            'a cached class-like must not answer a function lookup of the same name',
        );
    }

    public function testSearchClassLikeIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->backend(self::createStub(NamespaceCatalog::class))->search('Array', NameKind::ClassLike),
            'a bare prefix must not surface built-ins that do not resolve unqualified',
        );
    }

    public function testSearchFindsBuiltinFunctions(): void
    {
        $results = $this->backendWithSearch()->search('str_contains', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'str_contains',
            $fqns,
            'a built-in function must be found by prefix search',
        );
    }

    public function testSearchFindsBuiltinConstants(): void
    {
        $results = $this->backendWithSearch()->search('PHP_INT_M', NameKind::Constant);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'PHP_INT_MAX',
            $fqns,
            'a built-in constant must be found by prefix search',
        );
    }

    public function testSearchReturnsCorrectSymbolKindForFunctions(): void
    {
        $results = $this->backendWithSearch()->search('str_contains', NameKind::Function_);

        self::assertNotEmpty($results, 'the prefix must match at least one function');
        foreach ($results as $symbol) {
            self::assertSame(
                SymbolKind::Function_,
                $symbol->kind,
                'every symbol returned for a Function_ search must carry SymbolKind::Function_',
            );
        }
    }

    public function testChildrenOfForwardsToTheReflectionCatalog(): void
    {
        $expected = new NamespaceContents(['Random'], []);
        $catalog = $this->createMock(NamespaceCatalog::class);
        $catalog->expects($this->once())
            ->method('childrenOf')
            ->with('')
            ->willReturn($expected);

        self::assertSame(
            $expected,
            $this->backend($catalog)->childrenOf(new NamespaceName('')),
            'enumeration must forward the namespace path to the catalog and return its result',
        );
    }
}
