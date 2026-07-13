<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Resolution\Reference;
use Firehed\PhpLsp\Resolution\ReferenceKind;
use Firehed\PhpLsp\Resolution\ReferenceResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cases are derived from the PHP manual, "Name resolution rules"
 * (language.namespaces.rules):
 *
 * - Rule 3: a qualified name's first segment is translated via the
 *   class/namespace import table — regardless of the leaf symbol's kind.
 * - Rule 5: an unqualified name is translated via the import table for its own
 *   symbol type (class / function / constant).
 * - Rule 6: an unqualified class-like name prepends the current namespace; it
 *   never falls back to global.
 * - Rule 7: an unqualified function or constant name falls back to global.
 */
#[CoversClass(Reference::class)]
#[CoversClass(ReferenceResolver::class)]
#[CoversClass(NameContext::class)]
#[CoversClass(NameKind::class)]
class ReferenceResolverTest extends TestCase
{
    #[DataProvider('provideReferences')]
    public function testResolve(
        string $fqn,
        NameKind $kind,
        NameContext $context,
        string $expectedText,
        ReferenceKind $expectedKind,
    ): void {
        $reference = ReferenceResolver::resolve($fqn, $kind, $context);

        self::assertSame(
            $expectedText,
            $reference->text,
            'The reference text must be the shortest form that resolves to the symbol',
        );
        self::assertSame(
            $expectedKind,
            $reference->kind,
            'The reference kind identifies why it resolves, and drives ranking',
        );
    }

    #[DataProvider('provideImportTables')]
    public function testImportsForConsultsTheTableForTheKind(NameKind $kind, string $expected): void
    {
        $context = new NameContext(
            'App',
            classImports: ['Thing' => 'Other\Thing'],
            functionImports: ['helper' => 'Other\helper'],
            constantImports: ['FOO' => 'Other\FOO'],
        );

        self::assertSame(
            [$expected],
            array_values($context->importsFor($kind)),
            'An unqualified name is resolved against the import table for its own kind',
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{NameKind, string}>
     */
    public static function provideImportTables(): iterable
    {
        yield 'class-likes use the class table' => [NameKind::ClassLike, 'Other\Thing'];
        yield 'functions use the function table' => [NameKind::Function_, 'Other\helper'];
        yield 'constants use the constant table' => [NameKind::Constant, 'Other\FOO'];
    }

    #[DataProvider('provideReachability')]
    public function testIsReachable(ReferenceKind $kind, bool $expected): void
    {
        $reference = new Reference('Whatever', $kind);

        self::assertSame(
            $expected,
            $reference->isReachable(),
            'Only an unreachable reference requires qualification or an added import',
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{ReferenceKind, bool}>
     */
    public static function provideReachability(): iterable
    {
        yield 'current namespace' => [ReferenceKind::CurrentNamespace, true];
        yield 'import' => [ReferenceKind::Import, true];
        yield 'prefix import' => [ReferenceKind::PrefixImport, true];
        yield 'sub namespace' => [ReferenceKind::SubNamespace, true];
        yield 'global fallback' => [ReferenceKind::GlobalFallback, true];
        yield 'unreachable' => [ReferenceKind::Unreachable, false];
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, NameKind, NameContext, string, ReferenceKind}>
     */
    public static function provideReferences(): iterable
    {
        // Rule 6 / same namespace: the last segment is enough.
        yield 'global class from global namespace' => [
            'Exception',
            NameKind::ClassLike,
            new NameContext(''),
            'Exception',
            ReferenceKind::CurrentNamespace,
        ];
        yield 'class in current namespace' => [
            'App\Thing',
            NameKind::ClassLike,
            new NameContext('App'),
            'Thing',
            ReferenceKind::CurrentNamespace,
        ];
        yield 'namespace comparison is case-insensitive' => [
            'App\Thing',
            NameKind::ClassLike,
            new NameContext('app'),
            'Thing',
            ReferenceKind::CurrentNamespace,
        ];
        yield 'function in current namespace' => [
            'App\helper',
            NameKind::Function_,
            new NameContext('App'),
            'helper',
            ReferenceKind::CurrentNamespace,
        ];

        // An import binding the short name to a *different* symbol shadows the
        // same-namespace one, leaving it reachable only when fully qualified.
        yield 'same-namespace class shadowed by an import' => [
            'App\Thing',
            NameKind::ClassLike,
            new NameContext('App', classImports: ['Thing' => 'Other\Thing']),
            '\App\Thing',
            ReferenceKind::Unreachable,
        ];

        // Rule 5: unqualified names use the import table for their own kind.
        yield 'exact class import' => [
            'Psr\Log\LoggerInterface',
            NameKind::ClassLike,
            new NameContext('App', classImports: ['LoggerInterface' => 'Psr\Log\LoggerInterface']),
            'LoggerInterface',
            ReferenceKind::Import,
        ];
        yield 'aliased class import' => [
            'Psr\Log\LoggerInterface',
            NameKind::ClassLike,
            new NameContext('App', classImports: ['Logger' => 'Psr\Log\LoggerInterface']),
            'Logger',
            ReferenceKind::Import,
        ];
        yield 'use function import' => [
            'Other\helper',
            NameKind::Function_,
            new NameContext('App', functionImports: ['helper' => 'Other\helper']),
            'helper',
            ReferenceKind::Import,
        ];
        yield 'use const import' => [
            'Other\FOO',
            NameKind::Constant,
            new NameContext('App', constantImports: ['FOO' => 'Other\FOO']),
            'FOO',
            ReferenceKind::Import,
        ];
        yield 'a class import does not make an unqualified function reachable' => [
            'Other\Thing',
            NameKind::Function_,
            new NameContext('App', classImports: ['Thing' => 'Other\Thing']),
            '\Other\Thing',
            ReferenceKind::Unreachable,
        ];

        // Rule 3: a qualified name's first segment always uses the class table.
        yield 'prefix import' => [
            'Psr\Log\LoggerInterface',
            NameKind::ClassLike,
            new NameContext('App', classImports: ['Log' => 'Psr\Log']),
            'Log\LoggerInterface',
            ReferenceKind::PrefixImport,
        ];
        yield 'longest prefix import wins' => [
            'Psr\Log\LoggerInterface',
            NameKind::ClassLike,
            new NameContext('App', classImports: ['Psr' => 'Psr', 'Log' => 'Psr\Log']),
            'Log\LoggerInterface',
            ReferenceKind::PrefixImport,
        ];
        yield 'longest prefix import wins regardless of import order' => [
            'Psr\Log\LoggerInterface',
            NameKind::ClassLike,
            new NameContext('App', classImports: ['Log' => 'Psr\Log', 'Psr' => 'Psr']),
            'Log\LoggerInterface',
            ReferenceKind::PrefixImport,
        ];
        yield 'an import may be both a class and a namespace prefix' => [
            'App\Model\User\Repository',
            NameKind::ClassLike,
            new NameContext('App', classImports: ['User' => 'App\Model\User']),
            'User\Repository',
            ReferenceKind::PrefixImport,
        ];
        yield 'a qualified function uses the class import table' => [
            'App\Model\helper',
            NameKind::Function_,
            new NameContext('App', classImports: ['Model' => 'App\Model']),
            'Model\helper',
            ReferenceKind::PrefixImport,
        ];

        // Sub-namespace of the current namespace: a relative qualified name.
        yield 'class in a sub-namespace' => [
            'App\Sub\Thing',
            NameKind::ClassLike,
            new NameContext('App'),
            'Sub\Thing',
            ReferenceKind::SubNamespace,
        ];
        yield 'namespaced class from the global namespace' => [
            'Psr\Log\LoggerInterface',
            NameKind::ClassLike,
            new NameContext(''),
            'Psr\Log\LoggerInterface',
            ReferenceKind::SubNamespace,
        ];
        yield 'sub-namespace shadowed by an import of its first segment' => [
            'App\Sub\Thing',
            NameKind::ClassLike,
            new NameContext('App', classImports: ['Sub' => 'Other\Sub']),
            '\App\Sub\Thing',
            ReferenceKind::Unreachable,
        ];

        // Rule 7: functions and constants fall back to global; classes do not.
        yield 'global function from a namespace' => [
            'strlen',
            NameKind::Function_,
            new NameContext('App'),
            'strlen',
            ReferenceKind::GlobalFallback,
        ];
        yield 'global constant from a namespace' => [
            'PHP_EOL',
            NameKind::Constant,
            new NameContext('App'),
            'PHP_EOL',
            ReferenceKind::GlobalFallback,
        ];
        yield 'global fallback shadowed by a function import' => [
            'strlen',
            NameKind::Function_,
            new NameContext('App', functionImports: ['strlen' => 'Other\strlen']),
            '\strlen',
            ReferenceKind::Unreachable,
        ];
        yield 'a global class has no fallback from a namespace' => [
            'Exception',
            NameKind::ClassLike,
            new NameContext('App'),
            '\Exception',
            ReferenceKind::Unreachable,
        ];

        // Unrelated namespaces are only reachable fully qualified.
        yield 'class in an unrelated namespace' => [
            'Other\Thing',
            NameKind::ClassLike,
            new NameContext('App'),
            '\Other\Thing',
            ReferenceKind::Unreachable,
        ];

        // Constant names are case-sensitive; their namespace is not.
        yield 'constant short names are case-sensitive' => [
            'App\MY_CONST',
            NameKind::Constant,
            new NameContext('App', constantImports: ['my_const' => 'App\my_const']),
            'MY_CONST',
            ReferenceKind::CurrentNamespace,
        ];
    }
}
