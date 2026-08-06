<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Knowledge\BuiltinBackend;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use PHPUnit\Framework\TestCase;

/**
 * The built-in backend is the lowest-precedence source (RFC 1 §5.3): it reflects the
 * symbols the server runtime has loaded. These prove class-like lookup via
 * reflection, its caching, absence for an unknown name, the empty prefix search, and
 * that enumeration forwards to the reflection catalog.
 */
final class BuiltinBackendTest extends TestCase
{
    private function backend(NamespaceCatalog $namespaces): BuiltinBackend
    {
        return new BuiltinBackend(new DefaultClassInfoFactory(), $namespaces, CacheFactory::inMemory());
    }

    public function testLookupClassLikeReflectsABuiltinClass(): void
    {
        $info = $this->backend(self::createStub(NamespaceCatalog::class))
            ->lookupClassLike(self::className(\ArrayObject::class));

        self::assertNotNull($info, 'a loaded built-in class must resolve through reflection');
        self::assertSame('ArrayObject', $info->name->fqn, 'the reflected class must be returned');
    }

    public function testLookupClassLikeReturnsNullForAnUnknownClass(): void
    {
        self::assertNull(
            $this->backend(self::createStub(NamespaceCatalog::class))
                ->lookupClassLike(self::className('No\Such\Builtin')),
            'a name reflection cannot load is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupClassLikeCachesAResolvedClass(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalog::class));
        $name = self::className(\ArrayObject::class);

        $first = $backend->lookupClassLike($name);
        $second = $backend->lookupClassLike($name);

        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');
        self::assertSame($first, $second, 'a second lookup must return the cached instance, not re-reflect');
    }

    public function testLookupFunctionReflectsABuiltinFunction(): void
    {
        $info = $this->backend(self::createStub(NamespaceCatalog::class))
            ->lookupFunction(FunctionName::fromFullyQualified('str_contains'));

        self::assertNotNull($info, 'a built-in function must resolve through reflection');
        self::assertSame('str_contains', $info->name);
        self::assertCount(2, $info->parameters, 'the reflected signature must be carried');
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        self::assertNotNull(
            $this->backend(self::createStub(NamespaceCatalog::class))
                ->lookupFunction(FunctionName::fromFullyQualified('STR_CONTAINS')),
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
            $this->backend(self::createStub(NamespaceCatalog::class))
                ->lookupFunction(FunctionName::fromFullyQualified('testDocumentedFunction')),
            'a userland function loaded in the server process is not a built-in',
        );
    }

    public function testLookupFunctionReturnsNullForAnUnknownFunction(): void
    {
        self::assertNull(
            $this->backend(self::createStub(NamespaceCatalog::class))
                ->lookupFunction(FunctionName::fromFullyQualified('no_such_builtin')),
            'a name reflection cannot load is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupFunctionCachesAResolvedFunction(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalog::class));
        $name = FunctionName::fromFullyQualified('str_contains');

        $first = $backend->lookupFunction($name);
        $second = $backend->lookupFunction($name);

        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');
        self::assertSame($first, $second, 'a second lookup must return the cached instance, not re-reflect');
    }

    public function testFunctionAndClassLikeCachesDoNotCollide(): void
    {
        // PHP's three symbol namespaces are independent, so one name can be both a
        // class and a function. A cache keyed on the name alone would serve a
        // ClassInfo to a function lookup.
        $backend = $this->backend(self::createStub(NamespaceCatalog::class));

        $backend->lookupClassLike(self::className(\ArrayObject::class));

        self::assertNull(
            $backend->lookupFunction(FunctionName::fromFullyQualified('ArrayObject')),
            'a cached class-like must not answer a function lookup of the same name',
        );
    }

    public function testSearchClassLikesIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->backend(self::createStub(NamespaceCatalog::class))->searchClassLikes('Array'),
            'a bare prefix must not surface built-ins that do not resolve unqualified',
        );
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

    private static function className(string $fqn): ClassName
    {
        /** @phpstan-ignore argument.type (virtual names are not analyzed) */
        return new ClassName($fqn);
    }
}
