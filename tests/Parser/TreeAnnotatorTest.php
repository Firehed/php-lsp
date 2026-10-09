<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TreeAnnotator::class)]
final class TreeAnnotatorTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testAnnotateAddsParentAttributeToNestedNodes(): void
    {
        $tree = (new ParserFactory())->createForNewestSupportedVersion()->parse(
            '<?php namespace A; class Foo {}',
        ) ?? [];

        $annotated = (new TreeAnnotator())->annotate($tree);

        $namespace = $annotated[0];
        self::assertInstanceOf(Namespace_::class, $namespace);
        $class = $namespace->stmts[0];
        self::assertInstanceOf(Class_::class, $class);
        self::assertSame(
            $namespace,
            $class->getAttribute('parent'),
            'ParentConnectingVisitor must have set the parent attribute so scope walks work',
        );
    }

    public function testAnnotateResolvesNamesRelativeToImports(): void
    {
        $tree = (new ParserFactory())->createForNewestSupportedVersion()->parse(
            '<?php namespace A; use B\\Bar; new Bar();',
        ) ?? [];

        $annotated = (new TreeAnnotator())->annotate($tree);

        $namespace = $annotated[0];
        self::assertInstanceOf(Namespace_::class, $namespace);
        $new = $namespace->stmts[1] ?? null;
        self::assertNotNull($new);
        $className = self::extractNewName($new);
        self::assertInstanceOf(Name::class, $className);
        self::assertSame(
            'B\\Bar',
            $className->toString(),
            'NameResolver must have rewritten the aliased name to its imported fully qualified form',
        );
    }

    public function testTolerantAnnotationContinuesPastANameResolutionError(): void
    {
        $tree = (new ParserFactory())->createForNewestSupportedVersion()->parse(
            $this->loadFixture('TopLevel/duplicate_imports.php'),
        ) ?? [];

        $annotated = (new TreeAnnotator(tolerant: true))->annotate($tree);

        $namespace = $annotated[0];
        self::assertInstanceOf(Namespace_::class, $namespace);
        $className = self::extractNewName($namespace->stmts[2]);
        self::assertInstanceOf(Name::class, $className);
        self::assertSame('Fixtures\\Domain\\User', $className->toString(), 'the first import still resolves the name');
    }

    private static function extractNewName(\PhpParser\Node $node): ?Name
    {
        if (!$node instanceof \PhpParser\Node\Stmt\Expression) {
            return null;
        }
        $expr = $node->expr;
        if (!$expr instanceof \PhpParser\Node\Expr\New_) {
            return null;
        }
        $class = $expr->class;
        return $class instanceof Name ? $class : null;
    }
}
