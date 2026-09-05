<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkeletonSyntaxSource::class)]
final class SkeletonSyntaxSourceTest extends TestCase
{
    private SkeletonSyntaxSource $source;

    protected function setUp(): void
    {
        $this->source = new SkeletonSyntaxSource();
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

    /**
     * @param class-string<Stmt\ClassLike> $expected
     */
    #[DataProvider('classLikeKinds')]
    public function testEachClassLikeKeywordMapsToItsStmt(string $keyword, string $name, string $expected): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace App;\n{$keyword} {$name} {}\n",
        ));

        self::assertCount(1, (new NodeFinder())->findInstanceOf($ast, $expected));
    }

    /**
     * @return array<string, array{string, string, class-string<Stmt\ClassLike>}>
     */
    public static function classLikeKinds(): array
    {
        return [
            'interface' => ['interface', 'Openable', Stmt\Interface_::class],
            'trait' => ['trait', 'HasTimestamps', Stmt\Trait_::class],
            'enum' => ['enum', 'Status', Stmt\Enum_::class],
        ];
    }

    public function testTopLevelClassesAreEmittedWithoutANamespace(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nclass Global_ {}\n",
        ));

        self::assertCount(1, $ast, 'a namespace-less file returns its class-likes directly');
        self::assertInstanceOf(Stmt\Class_::class, $ast[0]);
    }

    public function testAnEmptyBracedNamespaceEmitsANamespaceNodeWithoutAName(): void
    {
        // `namespace {}` is PHP for a braced global namespace: no name, braced body.
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace {\n    class Local {}\n}\n",
        ));

        self::assertInstanceOf(Stmt\Namespace_::class, $ast[0]);
        self::assertNull($ast[0]->name, 'a braced global namespace carries no name node');
    }

    public function testAProtectedMemberModifierIsRecovered(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace A;\nclass C\n{\n    protected function guarded(): void {}\n}\n",
        ));

        $methods = (new NodeFinder())->findInstanceOf($ast, Stmt\ClassMethod::class);
        self::assertCount(1, $methods);
        self::assertTrue($methods[0]->isProtected(), 'the protected modifier maps to its flag');
    }

    public function testAConstImportIsTaggedAsAConstantUse(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace A;\nuse const Vendor\\PI;\n",
        ));

        $uses = (new NodeFinder())->findInstanceOf($ast, Stmt\Use_::class);
        self::assertCount(1, $uses);
        self::assertSame(Stmt\Use_::TYPE_CONSTANT, $uses[0]->type);
    }

    public function testATraitUseInsideAClassBodyIsNotReadAsANamespaceImport(): void
    {
        // The trait `use SomeTrait;` sits at a deeper brace depth than the
        // namespace-scope imports; the depth check keeps it out of the
        // namespace's imports and stretches braceDepthAt through the class
        // body's closing brace.
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace A;\nuse Real\\Import;\nclass C {\n    use SomeTrait;\n}\nuse After\\Close;\n",
        ));

        $uses = (new NodeFinder())->findInstanceOf($ast, Stmt\Use_::class);
        $names = array_map(static fn (Stmt\Use_ $u) => $u->uses[0]->name->toString(), $uses);
        self::assertSame(
            ['Real\\Import', 'After\\Close'],
            $names,
            'the trait use must not appear alongside namespace imports',
        );
    }

    public function testAnUnclosedBracedNamespaceRunsToEndOfFile(): void
    {
        // The braced-namespace slice runs to end-of-file when the brace is
        // unclosed, so a member declared inside it is still visible.
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace App {\n    class Partial {}\n",
        ));

        self::assertInstanceOf(Stmt\Namespace_::class, $ast[0]);
        self::assertCount(
            1,
            (new NodeFinder())->findInstanceOf($ast, Stmt\Class_::class),
            'the class inside an unclosed braced namespace still lands in the tree',
        );
    }

    public function testAClassLikeWithNoOpeningBraceSpansToEndOfFile(): void
    {
        // A truncated declaration with no `{` anywhere after it still yields the
        // class-like; the body slice runs to end-of-file.
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace App;\nclass Truncated",
        ));

        $classes = (new NodeFinder())->findInstanceOf($ast, Stmt\Class_::class);
        self::assertCount(1, $classes, 'a brace-less declaration still yields the class-like');
        self::assertSame('Truncated', $classes[0]->name?->toString());
    }

    public function testAGroupUseWithATrailingCommaSkipsTheEmptyItem(): void
    {
        $ast = $this->source->parse(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace A;\nuse Vendor\\{A, B,};\n",
        ));

        $groups = (new NodeFinder())->findInstanceOf($ast, Stmt\GroupUse::class);
        self::assertCount(1, $groups);
        self::assertCount(2, $groups[0]->uses, 'an empty item between commas contributes no UseItem');
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
