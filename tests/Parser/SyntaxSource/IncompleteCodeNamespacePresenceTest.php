<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every fixture under {@see tests/Fixtures/src/IncompleteCode/} that declares a
 * namespace must reach a downstream reader through the composite carrying that
 * namespace node — the composite's first-non-empty rule must not lose a
 * namespace that today's namespace-or-use fallback check kept.
 *
 * Where php-parser recovery yields a tree without the namespace, the skeleton
 * would fill it in — except the composite already stopped at the earlier
 * member. This is what the test would catch: a fixture where the parsed tree
 * lacks the namespace and completion silently loses the imports.
 */
#[CoversNothing]
final class IncompleteCodeNamespacePresenceTest extends TestCase
{
    private CompositeSyntaxSource $composite;

    protected function setUp(): void
    {
        $this->composite = new CompositeSyntaxSource([
            new PhpParserSyntaxSource(new TreeAnnotator(), new ParseMetrics()),
            new SkeletonSyntaxSource(),
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namespacedFixtures(): iterable
    {
        $dir = __DIR__ . '/../../Fixtures/src/IncompleteCode';
        $entries = scandir($dir);
        self::assertNotFalse($entries, 'IncompleteCode fixtures directory must be readable');
        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            $content = file_get_contents($path);
            self::assertNotFalse($content, "must read {$path}");
            if (preg_match('/^\s*namespace\s+/m', $content) !== 1) {
                continue;
            }
            yield $entry => [$path];
        }
    }

    #[DataProvider('namespacedFixtures')]
    public function testTheCompositePreservesTheNamespaceDeclaration(string $path): void
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);
        $document = new TextDocument('file://' . $path, 'php', 1, $content);

        $tree = $this->composite->parse($document);
        $namespaces = (new NodeFinder())->findInstanceOf($tree, Stmt\Namespace_::class);

        self::assertNotSame(
            [],
            $namespaces,
            "the composite must not drop the namespace declaration in {$path}: "
                . 'a php-parser recovery that dropped it would leave name resolution wrong for the whole file, '
                . 'and the skeleton must fill that in',
        );
    }
}
