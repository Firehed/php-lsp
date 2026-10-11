<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameContextFactory::class)]
final class NameContextFactoryTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return iterable<string, array{string, string, NameContext}>
     */
    public static function contexts(): iterable
    {
        $imports = 'Namespacing/ImportCompletion.php';
        $models = 'Fixtures\Namespacing\Models';
        yield 'imports of each kind, in the enclosing block' => [$imports, 'imported_class_partial', new NameContext(
            'Fixtures\Namespacing\ImportCompletion',
            classImports: [
                'User' => "{$models}\User",
                'Repo' => "{$models}\UserRepository",
                'SingletonTrait' => 'Fixtures\Traits\SingletonTrait',
            ],
            functionImports: ['makeUser' => "{$models}\makeUser"],
            constantImports: ['DEFAULT_LIMIT' => "{$models}\DEFAULT_LIMIT"],
        )];
        yield 'a group use, aliased member included, and no other block\'s imports' => [
            $imports,
            'grouped_import_partial',
            new NameContext('Fixtures\Namespacing\ImportCompletion\Grouped', classImports: [
                'User' => "{$models}\User",
                'Post' => "{$models}\Post",
                'Repos' => "{$models}\UserRepository",
            ]),
        ];
        yield 'a mixed group use, split by item kind' => [$imports, 'mixed_group_partial', new NameContext(
            'Fixtures\Namespacing\ImportCompletion\MixedGroup',
            classImports: ['UserRepository' => "{$models}\UserRepository"],
            functionImports: ['makeUser' => "{$models}\makeUser"],
            constantImports: ['DEFAULT_LIMIT' => "{$models}\DEFAULT_LIMIT"],
        )];
        yield 'one short name imported as a class and as a function' => [
            $imports,
            'colliding_partial',
            new NameContext(
                'Fixtures\Namespacing\ImportCompletion\Collision',
                classImports: ['Widget' => "{$models}\Widget"],
                functionImports: ['Widget' => 'Fixtures\Namespacing\Helpers\Widget'],
            ),
        ];
        yield 'a file with no namespace' => ['TopLevel/simple_aliased.php', 'simple_alias', new NameContext(
            '',
            classImports: ['Alias' => 'Vendor\Package\ClassName'],
        )];
    }

    #[DataProvider('contexts')]
    public function testReadsTheImportsInEffectAtALine(string $fixture, string $marker, NameContext $expected): void
    {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///t.php', 'php', 0, $content);
        $ast = (new PhpParserSyntaxSource(new TreeAnnotator()))->parse($document)->tree;

        self::assertEquals(
            $expected,
            NameContextFactory::fromAst($ast, $this->locateCursor($content, $marker)['line']),
            'the namespace enclosing the line, and each import in the table for its kind',
        );
    }
}
