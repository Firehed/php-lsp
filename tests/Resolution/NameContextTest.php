<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Resolution\NameContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameContext::class)]
final class NameContextTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('candidatesCases')]
    public function testCandidates(
        NameContext $context,
        string $short,
        NameKind $kind,
        array $expected,
        string $message,
    ): void {
        self::assertSame($expected, $context->candidates($short, $kind), $message);
    }

    /**
     * @return iterable<string, array{NameContext, string, NameKind, list<string>, string}>
     */
    public static function candidatesCases(): iterable
    {
        $noImports = new NameContext('App\\Models');
        $withImports = new NameContext(
            namespace: 'App\\Controllers',
            classImports: ['User' => 'App\\Models\\User', 'DB' => 'Illuminate\\Database\\DB'],
            functionImports: ['dump' => 'Symfony\\VarDumper\\dump'],
            constantImports: ['PHP_INT_MAX' => 'PHP_INT_MAX'],
        );
        $global = new NameContext('');

        // Fully qualified — always one candidate, kind irrelevant
        yield 'fq class' => [
            $noImports,
            '\\App\\Models\\User',
            NameKind::ClassLike,
            ['App\\Models\\User'],
            'Leading backslash stripped, returned as-is',
        ];

        // Unqualified class-like, in import table
        yield 'imported class' => [
            $withImports,
            'User',
            NameKind::ClassLike,
            ['App\\Models\\User'],
            'Import table match resolves to FQN',
        ];

        // Unqualified class-like, not imported, has namespace
        yield 'unimported class with namespace' => [
            $noImports,
            'Order',
            NameKind::ClassLike,
            ['App\\Models\\Order'],
            'Namespace prefix applied, no global fallback for classes',
        ];

        // Unqualified class-like, not imported, global namespace
        yield 'unimported class global' => [
            $global,
            'stdClass',
            NameKind::ClassLike,
            ['stdClass'],
            'No namespace prefix in global namespace',
        ];

        // Unqualified function, imported
        yield 'imported function' => [
            $withImports,
            'dump',
            NameKind::Function_,
            ['Symfony\\VarDumper\\dump'],
            'Function import resolves to FQN',
        ];

        // Unqualified function, not imported, has namespace
        yield 'unimported function with namespace' => [
            $noImports,
            'array_map',
            NameKind::Function_,
            ['App\\Models\\array_map', 'array_map'],
            'Functions try namespace first, then global fallback',
        ];

        // Unqualified function, not imported, global namespace
        yield 'unimported function global' => [
            $global,
            'array_map',
            NameKind::Function_,
            ['array_map'],
            'No duplicate when already in global namespace',
        ];

        // Unqualified constant, not imported, has namespace
        yield 'unimported constant with namespace' => [
            $noImports,
            'FOO',
            NameKind::Constant,
            ['App\\Models\\FOO', 'FOO'],
            'Constants try namespace first, then global fallback',
        ];

        // Partially qualified class, first segment imported
        yield 'partially qualified imported' => [
            $withImports,
            'DB\\Query',
            NameKind::ClassLike,
            ['Illuminate\\Database\\DB\\Query'],
            'First segment resolved from import, rest appended',
        ];

        // Partially qualified class, first segment not imported
        yield 'partially qualified unimported' => [
            $noImports,
            'Sub\\Thing',
            NameKind::ClassLike,
            ['App\\Models\\Sub\\Thing'],
            'Namespace prefix applied to full partially-qualified name',
        ];

        // Imported constant
        yield 'imported constant' => [
            $withImports,
            'PHP_INT_MAX',
            NameKind::Constant,
            ['PHP_INT_MAX'],
            'Constant import resolves to FQN',
        ];
    }
}
