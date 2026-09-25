<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\BuiltinBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The built-in backend is the lowest-precedence source (RFC 1 §5.3): it reflects the
 * symbols the server runtime has loaded. These prove lookup via reflection, its
 * caching, absence for an unknown name, the empty class-like prefix search, and
 * that namespace enumeration reads the same internal-symbol index the prefix
 * search does.
 */
final class BuiltinBackendTest extends TestCase
{
    use LooksUpBackendSymbolsTrait;

    private BuiltinBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new BuiltinBackend();
    }

    public function testLookupClassLikeReflectsABuiltinClass(): void
    {
        $info = self::classLikeIn($this->backend, \ArrayObject::class);

        self::assertNotNull($info, 'a loaded built-in class must resolve through reflection');
        self::assertSame(
            'ArrayObject',
            $info->name->qualifiedName->fullyQualifiedName(),
            'the reflected class must be returned',
        );
    }

    public function testLookupClassLikeReturnsNullForAnUnknownClass(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend, 'No\Such\Builtin'),
            'a name reflection cannot load is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupClassLikeDoesNotAutoloadUserCode(): void
    {
        // The LSP reads code; it must never execute it. A class-like lookup on
        // a user FQN — reached through the composite when higher-precedence
        // backends have all declined — must not trigger the SPL autoload
        // chain. Any registered autoloader (Composer's, a framework's) can
        // read files with arbitrary top-level side effects, including
        // fixtures with intentionally malformed PHP; a completion request
        // triggering that chain is unbounded code execution against the
        // serviced project.
        $trap = 'PhpLspAutoloadTrap\NotABuiltin';
        $observed = [];
        // Only the trap FQN is significant; unrelated autoloads (e.g. of the
        // test's own domain classes triggered by ClasslikeName construction)
        // are irrelevant noise.
        $tracker = static function (string $class) use ($trap, &$observed): void {
            if ($class === $trap) {
                $observed[] = $class;
            }
        };
        spl_autoload_register($tracker, prepend: true);

        try {
            $info = self::classLikeIn($this->backend, $trap);

            self::assertNull(
                $info,
                'the backend has no answer for a user FQN whatever autoload flag it used',
            );
            self::assertSame(
                [],
                $observed,
                'no autoloader may be invoked for the user FQN passed to this backend',
            );
        } finally {
            spl_autoload_unregister($tracker);
        }
    }

    public function testLookupFunctionReflectsABuiltinFunction(): void
    {
        $info = self::functionIn($this->backend, 'str_contains');

        self::assertNotNull($info, 'a built-in function must resolve through reflection');
        self::assertSame('str_contains', $info->name->qualifiedName->fullyQualifiedName());
        self::assertCount(2, $info->parameters, 'the reflected signature must be carried');
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        self::assertNotNull(
            self::functionIn($this->backend, 'STR_CONTAINS'),
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
            self::functionIn($this->backend, 'testDocumentedFunction'),
            'a userland function loaded in the server process is not a built-in',
        );
    }

    public function testLookupFunctionReturnsNullForAnUnknownFunction(): void
    {
        self::assertNull(
            self::functionIn($this->backend, 'no_such_builtin'),
            'a name reflection cannot load is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testSearchClassLikeIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->backend->search('Array', NameKind::ClassLike),
            'a bare prefix must not surface built-ins that do not resolve unqualified',
        );
    }

    public function testSearchFindsBuiltinFunctions(): void
    {
        $results = $this->backend->search('str_contains', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'str_contains',
            $fqns,
            'a built-in function must be found by prefix search',
        );
    }

    public function testSearchFindsBuiltinConstants(): void
    {
        $results = $this->backend->search('PHP_INT_M', NameKind::Constant);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'PHP_INT_MAX',
            $fqns,
            'a built-in constant must be found by prefix search',
        );
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $results = $this->backend->search('STR_CONTAINS', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'str_contains',
            $fqns,
            'prefix matching is case-insensitive because the user has not finished typing',
        );
    }

    public function testSearchDoesNotCrossKindBoundaries(): void
    {
        $results = $this->backend->search('Array', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertNotContains(
            'ArrayObject',
            $fqns,
            'a class-like must not appear in a function search',
        );
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        self::assertSame(
            [],
            $this->backend->search('zzNoMatch', NameKind::Function_),
            'a prefix that matches nothing must return an empty list',
        );
    }

    public function testSearchExcludesUserlandFunctions(): void
    {
        require_once dirname(__DIR__) . '/Domain/Fixtures/documented_function.php';

        $results = $this->backend->search('testDocumented', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertNotContains(
            'testDocumentedFunction',
            $fqns,
            'a userland function loaded in the server process must not appear in built-in search',
        );
    }

    public function testSearchReturnsCorrectSymbolKindForFunctions(): void
    {
        $results = $this->backend->search('str_contains', NameKind::Function_);

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
        $info = self::classLikeIn($this->backend, $fqn);

        self::assertNotNull($info, 'a class-like flavour reflection can describe must resolve');
        self::assertSame(
            $fqn,
            $info->name->qualifiedName->fullyQualifiedName(),
            'the reflected class-like must be returned',
        );
    }

    public function testLookupIgnoresClassLikesOnlyTheServerHasLoaded(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend, self::class),
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
        self::assertNull(
            self::symbolOfKindIn($this->backend, $fqn, $kind),
            'a name reflection cannot load for this kind is absent (RFC 1 §5.3)',
        );
    }

    public function testLookupResolvesABuiltinConstant(): void
    {
        self::assertNotNull(
            $this->backend->lookupConstant(ConstantName::fromFullyQualified('PHP_INT_MAX')),
            'a built-in constant must resolve',
        );
    }

    public function testLookupDoesNotResolveAUserConstant(): void
    {
        // Define a "user" constant that will be filtered out.
        if (!defined('TEST_USER_CONSTANT')) {
            define('TEST_USER_CONSTANT', 'value');
        }

        self::assertNull(
            $this->backend->lookupConstant(ConstantName::fromFullyQualified('TEST_USER_CONSTANT')),
            'a user-defined constant is not a built-in, so it must not resolve',
        );
    }

    public function testClassInfoCarriesBasicMetadataForAPlainClass(): void
    {
        $info = self::classLikeIn($this->backend, \stdClass::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(\stdClass::class, $info->name->qualifiedName->fullyQualifiedName());
        self::assertSame(ClassKind::Class_, $info->kind);
    }

    public function testClassInfoCapturesTheParentClass(): void
    {
        $info = self::classLikeIn($this->backend, \RuntimeException::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(\Exception::class, $info->parent?->qualifiedName->fullyQualifiedName());
    }

    public function testClassInfoReportsInterfaceKindForABuiltinInterface(): void
    {
        $info = self::classLikeIn($this->backend, \Iterator::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(ClassKind::Interface_, $info->kind);
    }

    public function testClassInfoReportsEnumKindAndEnumCases(): void
    {
        $info = self::classLikeIn($this->backend, \Random\IntervalBoundary::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertNotEmpty($info->enumCases, 'built-in enum cases must be extracted');
    }

    public function testClassInfoDetectsTheBuiltinAttributeClass(): void
    {
        $info = self::classLikeIn($this->backend, \Attribute::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertTrue($info->isAttribute, 'the built-in Attribute class is itself an attribute');
    }

    public function testPlainClassIsNotMarkedAsAttribute(): void
    {
        $info = self::classLikeIn($this->backend, \stdClass::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertFalse($info->isAttribute);
    }

    public function testClassInfoCarriesMethodsPropertiesAndInterfaces(): void
    {
        $info = self::classLikeIn($this->backend, \ArrayObject::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertNotEmpty($info->methods, 'a built-in class must report its methods');
        self::assertNotEmpty($info->interfaces, 'ArrayObject implements several built-in interfaces');
    }

    public function testClassInfoCarriesConstants(): void
    {
        $info = self::classLikeIn($this->backend, \ArrayObject::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertArrayHasKey('STD_PROP_LIST', $info->constants);
    }

    public function testInheritedConstantsAreFilteredFromDeclaringClass(): void
    {
        $info = self::classLikeIn($this->backend, \RecursiveDirectoryIterator::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(
            [],
            $info->constants,
            'the class declares no constants; the inherited FilesystemIterator constants must be filtered out',
        );
    }

    public function testNullDefaultParameterFormats(): void
    {
        $info = self::functionIn($this->backend, 'str_replace');

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
        $info = self::classLikeIn($this->backend, \Exception::class);

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
        $info = self::classLikeIn($this->backend, \SplHeap::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(
            Visibility::Protected,
            $info->methods['compare']->visibility,
            'SplHeap::compare is protected; the method visibility mapper must cover the Protected branch',
        );
    }

    public function testPrivateMethodVisibilityIsMapped(): void
    {
        $info = self::classLikeIn($this->backend, \Exception::class);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(
            Visibility::Private,
            $info->methods['__clone']->visibility,
            'Exception::__clone is private; the method visibility mapper must cover the Private branch',
        );
    }

    public function testGlobalNamespaceContainsBuiltinClassesFunctionsAndConstants(): void
    {
        $contents = $this->backend->childrenOf(new NamespaceName(''));

        self::assertContains(
            'Exception',
            self::symbolNames($contents, NameKind::ClassLike),
            'Built-in classes are in the global namespace and must be discoverable',
        );
        self::assertContains(
            'strlen',
            self::symbolNames($contents, NameKind::Function_),
            'Built-in functions must be discoverable',
        );
        self::assertContains(
            'PHP_EOL',
            self::symbolNames($contents, NameKind::Constant),
            'Built-in constants must be discoverable',
        );
    }

    public function testBuiltinInterfacesAreDiscoverable(): void
    {
        $contents = $this->backend->childrenOf(new NamespaceName(''));

        self::assertContains(
            'SessionHandlerInterface',
            self::symbolNames($contents, NameKind::ClassLike),
            'The interface from #308 that could never be offered before',
        );
    }

    public function testInternalSymbolsAreNotAssumedToBeGlobal(): void
    {
        $global = $this->backend->childrenOf(new NamespaceName(''));

        self::assertContains(
            'Random',
            $global->childNamespaces,
            'Random\ is an internal namespace, so the global namespace has a child',
        );
        self::assertNotContains(
            'Randomizer',
            self::symbolNames($global, NameKind::ClassLike),
            'Random\Randomizer is internal but namespaced; it does not belong to global',
        );

        self::assertContains(
            'Random\Randomizer',
            self::fqns($this->backend->childrenOf(new NamespaceName('Random'))),
            'An internal namespaced class is filed under its real namespace',
        );
    }

    public function testUserlandSymbolsAreExcludedFromChildrenOf(): void
    {
        $contents = $this->backend->childrenOf(new NamespaceName(''));

        self::assertNotContains(
            'Firehed',
            $contents->childNamespaces,
            'The language server\'s own classes are loaded in this process but are not built-ins',
        );
        self::assertNotContains(
            'PhpParser',
            $contents->childNamespaces,
            'Vendored dependencies of the server are not built-ins either',
        );
    }

    public function testUnknownNamespaceIsEmpty(): void
    {
        $contents = $this->backend->childrenOf(new NamespaceName('No\Such\Namespace'));

        self::assertSame([], $contents->childNamespaces, 'An unknown namespace has no children');
        self::assertSame([], $contents->symbols, 'An unknown namespace has no symbols');
    }

    /**
     * @return list<string>
     */
    private static function symbolNames(NamespaceContents $contents, NameKind $kind): array
    {
        $names = [];
        foreach ($contents->symbols as $symbol) {
            if ($symbol->kind === $kind) {
                $names[] = $symbol->shortName();
            }
        }

        return $names;
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
