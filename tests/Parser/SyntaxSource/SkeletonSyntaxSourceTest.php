<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkeletonSyntaxSource::class)]
final class SkeletonSyntaxSourceTest extends TestCase
{
    private SkeletonSyntaxSource $source;

    protected function setUp(): void
    {
        $this->source = new SkeletonSyntaxSource(new TreeAnnotator());
    }

    public function testEmptyContentYieldsNoStatements(): void
    {
        $ast = $this->source->parse(new TextDocument('file:///empty.php', 'php', 1, '<?php'));

        self::assertSame([], $ast, 'nothing declared yields no statements');
    }

    public function testABareNamespaceYieldsANamespaceNode(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace App;\n",
        ));

        self::assertCount(1, $ast, 'the namespace becomes one top-level statement');
        $ns = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $ns);
        self::assertSame('App', $ns->name?->toString(), 'the namespace name is recovered from the text');
        self::assertSame(
            Stmt\Namespace_::KIND_SEMICOLON,
            $ns->getAttribute('kind'),
            'ScopeFinder::findNamespaceNodeAtLine reads the kind attribute',
        );
    }

    public function testABracedNamespaceCarriesTheBracedKind(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace App {\n    class Widget {}\n}\n",
        ));

        self::assertInstanceOf(Stmt\Namespace_::class, $ast[0]);
        self::assertSame(Stmt\Namespace_::KIND_BRACED, $ast[0]->getAttribute('kind'));
    }

    public function testAClassWithMembersIsRecoveredWithModifiers(): void
    {
        $content = <<<'PHP'
        <?php
        namespace App;

        class Widget
        {
            public const NAME = 'widget';
            private static string $shared;
            public readonly int $id;

            public function open(): void {}
            private static function helper(): int {}
        }
        PHP;

        $ast = $this->source->parse(new TextDocument('file:///Widget.php', 'php', 1, $content));

        $classes = (new NodeFinder())->findInstanceOf($ast, Stmt\Class_::class);
        self::assertCount(1, $classes);
        $class = $classes[0];
        self::assertSame('App\\Widget', (string) $class->namespacedName, 'TreeAnnotator sets namespacedName');

        $methods = $class->getMethods();
        self::assertCount(2, $methods, 'both methods are recovered');
        self::assertSame('open', $methods[0]->name->toString());
        self::assertTrue($methods[0]->isPublic(), 'visibility flag rides on the modifier bit');
        self::assertFalse($methods[0]->isStatic());
        self::assertSame('helper', $methods[1]->name->toString());
        self::assertTrue($methods[1]->isPrivate());
        self::assertTrue($methods[1]->isStatic(), 'static modifier is recovered');

        $properties = [];
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                $properties[$item->name->toString()] = $property;
            }
        }
        self::assertArrayHasKey('shared', $properties);
        self::assertTrue($properties['shared']->isStatic());
        self::assertTrue($properties['shared']->isPrivate());
        self::assertArrayHasKey('id', $properties);
        self::assertTrue($properties['id']->isReadonly(), 'readonly modifier is recovered');

        $constants = [];
        foreach ((new NodeFinder())->findInstanceOf($class->stmts, Stmt\ClassConst::class) as $const) {
            foreach ($const->consts as $c) {
                $constants[$c->name->toString()] = $const;
            }
        }
        self::assertArrayHasKey('NAME', $constants);
        self::assertTrue($constants['NAME']->isPublic(), 'constant visibility defaults to public');
    }

    public function testExtendsAndImplementsAreRecovered(): void
    {
        $content = <<<'PHP'
        <?php
        namespace App;

        class Widget extends Base implements Openable, Sized
        {
        }
        PHP;

        $ast = $this->source->parse(new TextDocument('file:///Widget.php', 'php', 1, $content));

        $classes = (new NodeFinder())->findInstanceOf($ast, Stmt\Class_::class);
        self::assertCount(1, $classes);
        self::assertNotNull($classes[0]->extends);
        self::assertSame('App\\Base', $classes[0]->extends->toString(), 'extends is resolved through the namespace');
        self::assertCount(2, $classes[0]->implements);
        self::assertSame(['App\\Openable', 'App\\Sized'], array_map(
            static fn ($n) => $n->toString(),
            $classes[0]->implements,
        ));
    }

    public function testAnInterfaceIsRecognisedAsAnInterfaceStmt(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace App;\ninterface Openable {}\n",
        ));

        self::assertCount(1, (new NodeFinder())->findInstanceOf($ast, Stmt\Interface_::class));
    }

    public function testATraitIsRecognisedAsATraitStmt(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace App;\ntrait HasTimestamps {}\n",
        ));

        self::assertCount(1, (new NodeFinder())->findInstanceOf($ast, Stmt\Trait_::class));
    }

    public function testAnEnumIsRecognisedAsAnEnumStmt(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace App;\nenum Status {}\n",
        ));

        self::assertCount(1, (new NodeFinder())->findInstanceOf($ast, Stmt\Enum_::class));
    }

    public function testImportsBecomeUseStmts(): void
    {
        $content = <<<'PHP'
        <?php
        namespace App;

        use Vendor\Thing;
        use Vendor\Other as Aliased;
        use function Vendor\helper;
        use Vendor\{A, B as Renamed};
        PHP;

        $ast = $this->source->parse(new TextDocument('file:///a.php', 'php', 1, $content));

        $ns = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $ns);
        $uses = array_values(array_filter(
            $ns->stmts,
            static fn ($s) => $s instanceof Stmt\Use_ || $s instanceof Stmt\GroupUse,
        ));
        self::assertCount(4, $uses, 'each import statement becomes one Use node');
    }
}
