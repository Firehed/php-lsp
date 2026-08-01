<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Index\CachedNamespaceCatalog;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Knowledge\BuiltinBackend;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Utility\NamespacePath;
use PHPUnit\Framework\TestCase;

/**
 * Reflection-oracle parity for the built-in backend's *function* enumeration:
 * what `BuiltinBackend::childrenOf()` reports as functions must be exactly what
 * `get_defined_functions()['internal']` reports, name for name.
 *
 * This is an oracle, not a golden. The built-in function list differs across the
 * 8.3/8.4/8.5 CI matrix and with the extensions a machine happens to load, so it
 * cannot be frozen — but it can be compared against the same runtime truth the
 * production path reads today (`FunctionCandidates` calls
 * `get_defined_functions()` directly). That makes the comparison version-agnostic
 * in the same way `TypeGraphParityTest` uses reflection as the oracle for member
 * resolution.
 *
 * Why it exists now, in the slice *before* the migration: Step 3b moves function
 * completion off its direct `get_defined_functions()` call and onto the backend.
 * Freezing the shape of that path's output is the function-surface golden's job
 * ({@see FunctionSurfaceParityTest}); proving the backend can supply the same
 * *set* of names is this test's, and it has to be true before the migration is
 * attempted, not after.
 *
 * Note the two sides are not trivially the same call: reflection produces a flat
 * list of names, while the backend files each name under the namespace it
 * carries (an extension may declare namespaced functions) and answers one
 * namespace at a time. A name filed under the wrong namespace, or dropped while
 * indexing, fails here.
 *
 * See docs/architecture/0002-execution-plan.md, Step 3b; RFC 1 §4.2, §4.7, §5.3.
 */
final class BuiltinFunctionParityTest extends TestCase
{
    private BuiltinBackend $backend;

    protected function setUp(): void
    {
        // Assembled exactly as `KnowledgeStack::forProject` assembles the lowest-
        // precedence backend, so the oracle measures the shipped configuration.
        $this->backend = new BuiltinBackend(
            new DefaultClassInfoFactory(),
            new CachedNamespaceCatalog(new ReflectionNamespaceSource(), CacheFactory::inMemory()),
            CacheFactory::inMemory(),
        );
    }

    public function testEnumeratedFunctionsMatchReflection(): void
    {
        $expected = get_defined_functions()['internal'];
        sort($expected);

        $enumerated = $this->enumerateFunctions(self::namespacesOf($expected));
        sort($enumerated);

        self::assertSame(
            $expected,
            $enumerated,
            'the built-in backend must enumerate exactly the internal functions reflection reports',
        );
    }

    public function testGlobalNamespaceCarriesTheBulkOfTheFunctions(): void
    {
        // Guards the assertion above against a degenerate pass: if the backend
        // returned nothing *and* reflection somehow reported nothing, two empty
        // lists would still be `assertSame`. PHP's global built-ins are in the
        // hundreds on any build, so a floor well below the real count fails loudly
        // on an empty enumeration without pinning a version-fragile number.
        $global = $this->enumerateFunctions(['']);

        self::assertGreaterThan(
            500,
            count($global),
            'the global namespace must enumerate PHP\'s built-in functions, not an empty set',
        );
        self::assertContains(
            'array_map',
            $global,
            'a well-known global built-in must be enumerated under the global namespace',
        );
    }

    /**
     * @param list<string> $namespaces
     *
     * @return list<string>
     */
    private function enumerateFunctions(array $namespaces): array
    {
        $functions = [];
        foreach ($namespaces as $namespace) {
            foreach ($this->backend->childrenOf(new NamespaceName($namespace))->symbols as $symbol) {
                if ($symbol->kind === NameKind::Function_) {
                    $functions[] = self::qualifiedName($symbol);
                }
            }
        }

        return $functions;
    }

    /**
     * The distinct namespaces the reflected names live in — the global namespace
     * for core, plus any namespace an extension declares functions in.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function namespacesOf(array $names): array
    {
        $namespaces = [];
        foreach ($names as $name) {
            $namespaces[NamespacePath::namespaceOf($name)] = true;
        }

        return array_keys($namespaces);
    }

    /**
     * `get_defined_functions()` reports names lowercased, so the comparison is
     * made in that casing. Function names are case-insensitive in PHP, so this
     * normalizes a spelling difference, not a real one.
     */
    private static function qualifiedName(CatalogSymbol $symbol): string
    {
        return strtolower($symbol->fullyQualifiedName);
    }
}
