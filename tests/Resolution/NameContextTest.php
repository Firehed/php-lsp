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
    private const string GOLDEN_PATH = __DIR__ . '/goldens/name-context-candidates.json';

    public function testCandidatesMatchGolden(): void
    {
        $captured = [];
        foreach (self::candidatesFixtures() as $name => $case) {
            [$context, $short, $kind] = $case;
            $captured[$name] = [
                'namespace' => $context->namespace,
                'classImports' => $context->classImports,
                'functionImports' => $context->functionImports,
                'constantImports' => $context->constantImports,
                'short' => $short,
                'kind' => $kind->name,
                'candidates' => $context->candidates($short, $kind),
            ];
        }

        $encoded = json_encode($captured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if (getenv('UPDATE_GOLDENS') === '1') {
            file_put_contents(self::GOLDEN_PATH, $encoded);
        }

        self::assertFileExists(
            self::GOLDEN_PATH,
            'The name-context candidates golden must exist; capture with UPDATE_GOLDENS=1.',
        );
        self::assertSame(
            file_get_contents(self::GOLDEN_PATH),
            $encoded,
            'candidates() output must match the golden; step-44 requires it to stay byte-for-byte stable.',
        );
    }

    #[DataProvider('provideImportTables')]
    public function testImportsForConsultsTheTableForTheKind(NameKind $kind, string $expected): void
    {
        $context = new NameContext(
            'App',
            classImports: ['Thing' => 'Other\\Thing'],
            functionImports: ['helper' => 'Other\\helper'],
            constantImports: ['FOO' => 'Other\\FOO'],
        );

        self::assertSame(
            [$expected],
            array_values($context->importsFor($kind)),
            'An unqualified name reads the import table for its own kind',
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{NameKind, string}>
     */
    public static function provideImportTables(): iterable
    {
        yield 'class-likes use the class table' => [NameKind::ClassLike, 'Other\\Thing'];
        yield 'functions use the function table' => [NameKind::Function_, 'Other\\helper'];
        yield 'constants use the constant table' => [NameKind::Constant, 'Other\\FOO'];
    }

    /**
     * Every input the golden covers. Keeping them in one place makes it clear
     * which shapes the golden pins.
     *
     * @return iterable<string, array{NameContext, string, NameKind}>
     */
    private static function candidatesFixtures(): iterable
    {
        $noImports = new NameContext('App\\Models');
        $withImports = new NameContext(
            namespace: 'App\\Controllers',
            classImports: ['User' => 'App\\Models\\User', 'DB' => 'Illuminate\\Database\\DB'],
            functionImports: ['dump' => 'Symfony\\VarDumper\\dump'],
            constantImports: ['PHP_INT_MAX' => 'PHP_INT_MAX'],
        );
        $global = new NameContext('');

        yield 'fq class' => [$noImports, '\\App\\Models\\User', NameKind::ClassLike];
        yield 'imported class' => [$withImports, 'User', NameKind::ClassLike];
        yield 'unimported class with namespace' => [$noImports, 'Order', NameKind::ClassLike];
        yield 'unimported class global' => [$global, 'stdClass', NameKind::ClassLike];
        yield 'imported function' => [$withImports, 'dump', NameKind::Function_];
        yield 'unimported function with namespace' => [$noImports, 'array_map', NameKind::Function_];
        yield 'unimported function global' => [$global, 'array_map', NameKind::Function_];
        yield 'unimported constant with namespace' => [$noImports, 'FOO', NameKind::Constant];
        yield 'partially qualified imported' => [$withImports, 'DB\\Query', NameKind::ClassLike];
        yield 'partially qualified unimported' => [$noImports, 'Sub\\Thing', NameKind::ClassLike];
        yield 'imported constant' => [$withImports, 'PHP_INT_MAX', NameKind::Constant];
    }
}
