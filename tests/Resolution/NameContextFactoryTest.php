<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ParserOnlySyntaxSource;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameContextFactory::class)]
final class NameContextFactoryTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return iterable<string, array{string, NameContext}>
     */
    public static function contexts(): iterable
    {
        $imports = 'Namespacing/ImportCompletion.php';
        $models = 'Fixtures\Namespacing\Models';
        yield 'imports of each kind, in the enclosing block' => [$imports, new NameContext(
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
            new NameContext('Fixtures\Namespacing\ImportCompletion\Grouped', classImports: [
                'User' => "{$models}\User",
                'Post' => "{$models}\Post",
                'Repos' => "{$models}\UserRepository",
            ]),
        ];
        yield 'a mixed group use, split by item kind' => [$imports, new NameContext(
            'Fixtures\Namespacing\ImportCompletion\MixedGroup',
            classImports: ['UserRepository' => "{$models}\UserRepository"],
            functionImports: ['makeUser' => "{$models}\makeUser"],
            constantImports: ['DEFAULT_LIMIT' => "{$models}\DEFAULT_LIMIT"],
        )];
        yield 'one short name imported as a class and as a function' => [
            $imports,
            new NameContext(
                'Fixtures\Namespacing\ImportCompletion\Collision',
                classImports: ['Widget' => "{$models}\Widget"],
                functionImports: ['Widget' => 'Fixtures\Namespacing\Helpers\Widget'],
            ),
        ];
        yield 'a file with no namespace' => ['TopLevel/simple_aliased.php', new NameContext(
            '',
            classImports: ['Alias' => 'Vendor\Package\ClassName'],
        )];
    }

    #[DataProvider('contexts')]
    public function testReadsTheImportsInEffectInANamespace(string $fixture, NameContext $expected): void
    {
        $document = new TextDocument('file:///t.php', 'php', 0, $this->loadFixture($fixture));
        $tree = ParserOnlySyntaxSource::create()->parse($document)->tree;
        $namespace = array_find(
            $tree,
            static fn (Stmt $stmt): bool => $stmt instanceof Stmt\Namespace_
                && $stmt->name?->toString() === $expected->namespace,
        );

        self::assertEquals(
            $expected,
            NameContextFactory::in($namespace, $tree),
            'the block\'s own imports, each in the table for its kind',
        );
    }
}
