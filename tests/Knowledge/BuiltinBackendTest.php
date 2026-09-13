<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\NamespaceCatalogInterface;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\PrefixSearchableInterface;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\BuiltinBackend;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolCache;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function backend(NamespaceCatalogInterface $namespaces): BuiltinBackend
    {
        return new BuiltinBackend(
            $namespaces,
            new SymbolCache(CacheFactory::inMemory()),
            self::createStub(PrefixSearchableInterface::class),
        );
    }

    private function backendWithSearch(): BuiltinBackend
    {
        $reflectionSource = new ReflectionNamespaceSource();
        return new BuiltinBackend(
            $reflectionSource,
            new SymbolCache(CacheFactory::inMemory()),
            $reflectionSource,
        );
    }

    public function testLookupClassLikeReflectsABuiltinClass(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \ArrayObject::class);

        self::assertNotNull($info, 'a loaded built-in class must resolve through reflection');
        self::assertSame('ArrayObject', $info->name->fqn, 'the reflected class must be returned');
    }

    public function testLookupClassLikeReturnsNullForAnUnknownClass(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend(self::createStub(NamespaceCatalogInterface::class)), 'No\Such\Builtin'),
            'a name reflection cannot load is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupClassLikeCachesAResolvedClass(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));

        $first = self::classLikeIn($backend, \ArrayObject::class);
        $second = self::classLikeIn($backend, \ArrayObject::class);

        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');
        self::assertSame($first, $second, 'a second lookup must return the cached instance, not re-reflect');
    }

    public function testLookupFunctionReflectsABuiltinFunction(): void
    {
        $info = self::functionIn($this->backend(self::createStub(NamespaceCatalogInterface::class)), 'str_contains');

        self::assertNotNull($info, 'a built-in function must resolve through reflection');
        self::assertSame('str_contains', $info->name);
        self::assertCount(2, $info->parameters, 'the reflected signature must be carried');
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        self::assertNotNull(
            self::functionIn($this->backend(self::createStub(NamespaceCatalogInterface::class)), 'STR_CONTAINS'),
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
            self::functionIn(
                $this->backend(self::createStub(NamespaceCatalogInterface::class)),
                'testDocumentedFunction',
            ),
            'a userland function loaded in the server process is not a built-in',
        );
    }

    public function testLookupFunctionReturnsNullForAnUnknownFunction(): void
    {
        self::assertNull(
            self::functionIn($this->backend(self::createStub(NamespaceCatalogInterface::class)), 'no_such_builtin'),
            'a name reflection cannot load is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupFunctionCachesAResolvedFunction(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));

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
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));

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
            $this->backend(self::createStub(NamespaceCatalogInterface::class))->search('Array', NameKind::ClassLike),
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function loadedClassLikes(): iterable
    {
        yield 'class' => [\ArrayObject::class];
        yield 'interface' => [\Countable::class];
        yield 'enum' => [\Random\IntervalBoundary::class];
        // PHP has no built-in traits as of 8.5; if a future version adds one,
        // it should be added here.
        // yield 'trait' => [...];
    }

    #[DataProvider('loadedClassLikes')]
    public function testLookupBuildsClassInfoForEveryClassLikeFlavour(string $fqn): void
    {
        $info = self::classLikeIn($this->backend(self::createStub(NamespaceCatalogInterface::class)), $fqn);

        self::assertNotNull($info, 'a class-like flavour reflection can describe must resolve');
        self::assertSame($fqn, $info->name->fqn, 'the reflected class-like must be returned');
    }

    public function testLookupIgnoresClassLikesOnlyTheServerHasLoaded(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend(self::createStub(NamespaceCatalogInterface::class)), self::class),
            'a userland class loaded in the server process is not a built-in',
        );
    }

    /**
     * @return iterable<string, array{string, NameKind}>
     */
    public static function absentNames(): iterable
    {
        // The kind selects which reflection is consulted, so a name that exists in
        // one of PHP's symbol namespaces is not answered for another.
        yield 'a function asked for as a class' => ['str_contains', NameKind::ClassLike];
        yield 'a class asked for as a function' => [\ArrayObject::class, NameKind::Function_];
    }

    #[DataProvider('absentNames')]
    public function testLookupReturnsNullWhenReflectionCannotDescribeTheNameForThatKind(
        string $fqn,
        NameKind $kind,
    ): void {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        self::assertNull(
            $backend->lookup(QualifiedName::fromFullyQualified($fqn), $kind),
            'a name reflection cannot load for this kind is absent (RFC 1 §5.3)',
        );
    }

    public function testLookupResolvesABuiltinConstant(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));

        $info = $backend->lookup(QualifiedName::fromFullyQualified('PHP_INT_MAX'), NameKind::Constant);

        self::assertInstanceOf(
            ConstantInfo::class,
            $info,
            'a built-in constant must resolve to ConstantInfo',
        );
    }

    public function testLookupDoesNotResolveAUserConstant(): void
    {
        // Define a "user" constant that will be filtered out.
        if (!defined('TEST_USER_CONSTANT')) {
            define('TEST_USER_CONSTANT', 'value');
        }

        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));

        self::assertNull(
            $backend->lookup(QualifiedName::fromFullyQualified('TEST_USER_CONSTANT'), NameKind::Constant),
            'a user-defined constant is not a built-in, so it must not resolve',
        );
    }

    public function testClassInfoCarriesBasicMetadataForAPlainClass(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \stdClass::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(\stdClass::class, $info->name->fqn);
        self::assertSame(ClassKind::Class_, $info->kind);
    }

    public function testClassInfoCapturesTheParentClass(): void
    {
        $info = self::classLikeIn(
            $this->backend(self::createStub(NamespaceCatalogInterface::class)),
            \RuntimeException::class,
        );

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(\Exception::class, $info->parent?->fqn);
    }

    public function testClassInfoReportsInterfaceKindForABuiltinInterface(): void
    {
        $info = self::classLikeIn($this->backend(self::createStub(NamespaceCatalogInterface::class)), \Iterator::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(ClassKind::Interface_, $info->kind);
    }

    public function testClassInfoReportsEnumKindAndEnumCases(): void
    {
        $info = self::classLikeIn(
            $this->backend(self::createStub(NamespaceCatalogInterface::class)),
            \Random\IntervalBoundary::class,
        );

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertNotEmpty($info->enumCases, 'built-in enum cases must be extracted');
    }

    public function testClassInfoDetectsTheBuiltinAttributeClass(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \Attribute::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertTrue($info->isAttribute, 'the built-in Attribute class is itself an attribute');
    }

    public function testPlainClassIsNotMarkedAsAttribute(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \stdClass::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertFalse($info->isAttribute);
    }

    public function testClassInfoCarriesMethodsPropertiesAndInterfaces(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \ArrayObject::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertNotEmpty($info->methods, 'a built-in class must report its methods');
        self::assertNotEmpty($info->interfaces, 'ArrayObject implements several built-in interfaces');
    }

    public function testClassInfoCarriesConstants(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \ArrayObject::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertArrayHasKey('STD_PROP_LIST', $info->constants);
    }

    public function testInheritedConstantsAreFilteredFromDeclaringClass(): void
    {
        $info = self::classLikeIn(
            $this->backend(self::createStub(NamespaceCatalogInterface::class)),
            \RecursiveDirectoryIterator::class,
        );

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(
            [],
            $info->constants,
            'the class declares no constants; the inherited FilesystemIterator constants must be filtered out',
        );
    }

    public function testNullDefaultParameterFormats(): void
    {
        $info = self::functionIn($this->backend(self::createStub(NamespaceCatalogInterface::class)), 'str_replace');

        self::assertNotNull($info, 'str_replace must resolve so its parameters can be inspected');
        $paramsByName = [];
        foreach ($info->parameters as $param) {
            $paramsByName[$param->name] = $param;
        }

        self::assertArrayHasKey('count', $paramsByName, 'str_replace declares $count with a null default');
        self::assertTrue($paramsByName['count']->hasDefault);
        self::assertSame(
            'null',
            $paramsByName['count']->defaultValue,
            'a null default must be formatted as the literal string "null" (formatReflectionDefault Null branch)',
        );
    }

    public function testExceptionPropertyVisibilitiesAreMapped(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \Exception::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(
            Visibility::Protected,
            $info->properties['message']->visibility,
            'Exception::$message is protected; the visibility mapper must cover the Protected branch',
        );
        self::assertSame(
            Visibility::Private,
            $info->properties['string']->visibility,
            'Exception::$string is private; the visibility mapper must cover the Private branch',
        );
    }

    public function testProtectedMethodVisibilityIsMapped(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \SplHeap::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(
            Visibility::Protected,
            $info->methods['compare']->visibility,
            'SplHeap::compare is protected; the method visibility mapper must cover the Protected branch',
        );
    }

    public function testPrivateMethodVisibilityIsMapped(): void
    {
        $backend = $this->backend(self::createStub(NamespaceCatalogInterface::class));
        $info = self::classLikeIn($backend, \Exception::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(
            Visibility::Private,
            $info->methods['__clone']->visibility,
            'Exception::__clone is private; the method visibility mapper must cover the Private branch',
        );
    }

    public function testChildrenOfForwardsToTheReflectionCatalog(): void
    {
        $expected = new NamespaceContents(['Random'], []);
        $catalog = $this->createMock(NamespaceCatalogInterface::class);
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
