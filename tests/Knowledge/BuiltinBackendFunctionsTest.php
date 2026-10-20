<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\BuiltinBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The functions the backend reports must be exactly what
 * `get_defined_functions()['internal']` reports, name for name. The list varies
 * with the PHP version and loaded extensions, so it is compared against the
 * running PHP rather than written down.
 *
 * The two sides are not the same call: reflection produces a flat list, while
 * the backend files each name under its namespace (an extension may declare
 * namespaced functions) and answers one namespace at a time. A name filed under
 * the wrong namespace, or dropped while indexing, fails here.
 */
#[CoversClass(BuiltinBackend::class)]
final class BuiltinBackendFunctionsTest extends TestCase
{
    private BuiltinBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new BuiltinBackend();
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

    /**
     * Completion reads `search()`, which draws on an index of its own, so the
     * enumeration above does not vouch for it.
     */
    public function testSearchedFunctionsMatchReflection(): void
    {
        $expected = get_defined_functions()['internal'];
        sort($expected);

        $searched = array_map(
            static fn (QualifiedName $name): string => strtolower($name->fullyQualifiedName()),
            $this->backend->search('', NameKind::Function_),
        );
        sort($searched);

        self::assertSame(
            $expected,
            $searched,
            'searching every name must find exactly the internal functions reflection reports',
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
            $namespaces[NamespaceName::namespaceOf($name)] = true;
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
