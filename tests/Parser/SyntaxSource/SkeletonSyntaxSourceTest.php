<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkeletonSyntaxSource::class)]
final class SkeletonSyntaxSourceTest extends TestCase
{
    use LoadsFixturesTrait;

    private SkeletonSyntaxSource $source;

    protected function setUp(): void
    {
        $this->source = new SkeletonSyntaxSource();
    }

    public function testEmptyContentYieldsNoStatements(): void
    {
        $ast = $this->tree(new TextDocument('file:///empty.php', 'php', 1, '<?php'));

        self::assertSame([], $ast, 'nothing declared yields no statements');
    }

    public function testABareNamespaceYieldsANamespaceNode(): void
    {
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
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

        $ast = $this->tree(new TextDocument('file:///Widget.php', 'php', 1, $content));

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

        $ast = $this->tree(new TextDocument('file:///Widget.php', 'php', 1, $content));

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
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
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
        $ast = $this->tree(new TextDocument(
            'file:///a.php',
            'php',
            1,
            "<?php\nnamespace A;\nuse Vendor\\{A, B,};\n",
        ));

        $groups = (new NodeFinder())->findInstanceOf($ast, Stmt\GroupUse::class);
        self::assertCount(1, $groups);
        self::assertCount(2, $groups[0]->uses, 'an empty item between commas contributes no UseItem');
    }

    public function testAnUntypedParameterCarriesANullTypeNode(): void
    {
        $content = <<<'PHP'
        <?php
        namespace App;

        class Widget
        {
            public function open($handle) {}
        }
        PHP;

        $ast = $this->tree(new TextDocument('file:///w.php', 'php', 1, $content));

        $methods = (new NodeFinder())->findInstanceOf($ast, Stmt\ClassMethod::class);
        self::assertCount(1, $methods);
        self::assertCount(1, $methods[0]->params);
        self::assertNull(
            $methods[0]->params[0]->type,
            'parseTypeText returns null for a parameter that carries no type annotation',
        );
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

        $ast = $this->tree(new TextDocument('file:///a.php', 'php', 1, $content));

        $ns = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $ns);
        $uses = array_values(array_filter(
            $ns->stmts,
            static fn ($s) => $s instanceof Stmt\Use_ || $s instanceof Stmt\GroupUse,
        ));
        self::assertCount(4, $uses, 'each import statement becomes one Use node');
    }

    /**
     * Each extended or implemented name sits where it is written, as php-parser
     * places it, so a positional query finds it only under the cursor.
     */
    #[DataProvider('parentNameFixtures')]
    public function testParentNamesCarryTheirWrittenPositions(string $fixture): void
    {
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $this->loadFixture($fixture));
        $parsed = (new PhpParserSyntaxSource(new TreeAnnotator()))->parse($document)->tree;

        self::assertSame(
            self::describeParentNames($parsed),
            self::describeParentNames($this->tree($document)),
            'the skeleton must name and place each parent as php-parser does',
        );
    }

    /**
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('classMemberFixtures')]
    public function testEachClassHoldsOnlyItsOwnMembers(string $fixture, array $expected): void
    {
        $tree = $this->tree(new TextDocument('file:///' . $fixture, 'php', 1, $this->loadFixture($fixture)));

        $methods = [];
        foreach ((new NodeFinder())->findInstanceOf($tree, Stmt\Class_::class) as $class) {
            $methods[(string) $class->name] = array_map(
                fn (Stmt\ClassMethod $method) => $method->name->toString(),
                $class->getMethods(),
            );
        }

        self::assertSame($expected, $methods, 'a class holds only the members before the next declaration');
    }

    /**
     * @return array<string, array{string, array<string, list<string>>}>
     */
    public static function classMemberFixtures(): array
    {
        return [
            'indented class without its own brace' => [
                'TopLevel/truncated_class_in_braced_namespace.php',
                ['Truncated' => ['first'], 'Following' => ['second']],
            ],
            'modifier on its own line' => [
                'TopLevel/modifier_on_its_own_line.php',
                ['SplitDeclaration' => ['first'], 'Following' => ['second']],
            ],
        ];
    }

    /**
     * A class-like spans from its declaration to its closing brace, as
     * php-parser places it: not from the blank lines before it, and not past
     * its body when a modifier opens the declaration.
     */
    #[DataProvider('parentNameFixtures')]
    public function testClassLikesSpanWhereTheyAreWritten(string $fixture): void
    {
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $this->loadFixture($fixture));
        $parsed = (new PhpParserSyntaxSource(new TreeAnnotator()))->parse($document)->tree;

        self::assertSame(
            self::describeClassLikeSpans($parsed),
            self::describeClassLikeSpans($this->tree($document)),
            'the skeleton must span each class-like as php-parser does',
        );
    }

    /**
     * @param array<Stmt> $tree
     * @return list<array{string, int, int}>
     */
    private static function describeClassLikeSpans(array $tree): array
    {
        return array_values(array_map(
            fn (Stmt\ClassLike $classLike) => [
                (string) $classLike->name,
                $classLike->getStartFilePos(),
                $classLike->getEndFilePos(),
            ],
            (new NodeFinder())->findInstanceOf($tree, Stmt\ClassLike::class),
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parentNameFixtures(): array
    {
        return [
            'class extends' => ['src/Inheritance/ChildClass.php'],
            'class extends and implements' => ['src/Exception/AppException.php'],
            'interface extends a list' => ['src/Hierarchy/LeafInterface.php'],
            'final class after a blank line' => ['src/Inheritance/FinalDescendant.php'],
            'fully qualified parents' => ['src/Inheritance/GlobalParent.php'],
            'namespace-relative parent' => ['src/Inheritance/RelativeParent.php'],
        ];
    }

    #[DataProvider('nullableParameterFixtures')]
    public function testNullableParameterTypesCarryTheirWrittenPositions(string $fixture): void
    {
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $this->loadFixture($fixture));
        $parsed = (new PhpParserSyntaxSource(new TreeAnnotator()))->parse($document)->tree;

        self::assertSame(
            self::describeNullableParameterTypes($parsed),
            self::describeNullableParameterTypes($this->tree($document)),
            'the `?` belongs to the nullable type, and the name after it sits where it is written',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nullableParameterFixtures(): array
    {
        return [
            'imported class types' => ['src/TypeInference/BuiltinTypes.php'],
            'fully qualified type' => ['src/Inheritance/GlobalParent.php'],
            'primitive types' => ['src/TypeInference/NullablePrimitiveParameters.php'],
        ];
    }

    /**
     * @param array<Stmt> $tree
     * @return list<array{string, string, int, int, int, int}>
     */
    private static function describeNullableParameterTypes(array $tree): array
    {
        $described = [];
        foreach ((new NodeFinder())->findInstanceOf($tree, Node\Param::class) as $param) {
            if (
                !$param->type instanceof Node\NullableType
                || !$param->var instanceof Node\Expr\Variable
                || !is_string($param->var->name)
            ) {
                continue;
            }
            $described[] = [
                $param->var->name,
                $param->type->type->toString(),
                $param->type->getStartFilePos(),
                $param->type->getEndFilePos(),
                $param->type->type->getStartFilePos(),
                $param->type->type->getEndFilePos(),
            ];
        }
        return $described;
    }

    /**
     * @param array<Stmt> $tree
     * @return list<array{string, int, int}>
     */
    private static function describeParentNames(array $tree): array
    {
        $described = [];
        foreach ((new NodeFinder())->findInstanceOf($tree, Stmt\ClassLike::class) as $classLike) {
            $names = match (true) {
                $classLike instanceof Stmt\Class_ => [
                    ...($classLike->extends === null ? [] : [$classLike->extends]),
                    ...$classLike->implements,
                ],
                $classLike instanceof Stmt\Interface_ => $classLike->extends,
                $classLike instanceof Stmt\Enum_ => $classLike->implements,
                default => [],
            };
            foreach ($names as $name) {
                $described[] = [$name->toString(), $name->getStartFilePos(), $name->getEndFilePos()];
            }
        }
        return $described;
    }

    /**
     * @return array<Stmt>
     */
    private function tree(TextDocument $document): array
    {
        $parsed = $this->source->parse($document);
        self::assertSame($document, $parsed->document, 'the tree is paired with the document it was parsed from');
        return $parsed->tree;
    }
}
