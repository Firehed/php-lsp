<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\LateBinding;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\Scope;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ParserOnlySyntaxSource;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Scope::class)]
class ScopeTest extends TestCase
{
    use AstTestHelperTrait;
    use LoadsFixturesTrait;

    public function testAFreeFunctionHasNoClassContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/GlobalScope.php'));
        $function = self::findFirst(Stmt\Function_::class, $ast, fn ($n) => $n->name->toString() === 'utilityFunction');

        $scope = Scope::at($function, $ast);

        self::assertSame([], $scope->getParams(), 'Free function fixture declares no parameters');
        self::assertNull($scope->getSelfName(), 'Free function has no enclosing class');
        self::assertNull($scope->lateBinding()->parent);
        self::assertNull($scope->getThisType(), '$this is not bound in a free function');
        self::assertNotEmpty($scope->getStatements(), 'Function body statements should be exposed');
    }

    public function testAMethodBindsThisAndSelf(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));

        $scope = Scope::at(self::method('methodWithThis', $ast), $ast);

        self::assertEquals(self::className('Fixtures\Utility\ScopePatterns'), $scope->getSelfName());
        self::assertNull($scope->lateBinding()->parent, 'ScopePatterns has no parent class');
        self::assertSame('Fixtures\Utility\ScopePatterns', $scope->getThisType()?->qualifiedName->fullyQualifiedName());
    }

    public function testAChildMethodHasAParentContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Inheritance/ParentClass.php'));

        $scope = Scope::at(self::method('parentMethod', $ast), $ast);

        self::assertEquals(self::className('Fixtures\Inheritance\ParentClass'), $scope->getSelfName());
        self::assertEquals(self::className('Fixtures\Inheritance\Grandparent'), $scope->lateBinding()->parent);
    }

    public function testATraitMethodHasNoParentContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Traits/HasTimestamps.php'));

        $scope = Scope::at(self::method('getCreatedAt', $ast), $ast);

        self::assertEquals(self::className('Fixtures\Traits\HasTimestamps'), $scope->getSelfName());
        self::assertNull($scope->lateBinding()->parent, 'A trait has no extends clause');
        self::assertSame('Fixtures\Traits\HasTimestamps', $scope->getThisType()?->qualifiedName->fullyQualifiedName());
    }

    #[DataProvider('thisBindings')]
    public function testThisIsBoundWherePhpBindsIt(string $variable, ?string $expected): void
    {
        $ast = self::parseWithParents($this->loadFixture('TopLevel/this_binding.php'));
        $node = self::findVariableNode($variable, $ast);
        self::assertNotNull($node);

        self::assertSame(
            $expected,
            Scope::at($node, $ast)->getThisType()?->qualifiedName->fullyQualifiedName(),
            "\$this where \${$variable} is",
        );
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function thisBindings(): array
    {
        $class = 'Fixtures\ThisBinding\ThisBinding';
        return [
            'an instance method' => ['inInstanceMethod', $class],
            'a closure in an instance method' => ['inClosure', $class],
            'an arrow function in an instance method' => ['inArrow', $class],
            'a static closure' => ['inStaticClosure', null],
            'a static arrow function' => ['inStaticArrow', null],
            'a static method' => ['inStaticMethod', null],
            'a closure in a static method' => ['inClosureInStaticMethod', null],
            'a closure in a free function' => ['inClosureInFunction', null],
            'a property hook' => ['inPropertyHook', $class],
        ];
    }

    public function testAClosureExposesItsUseCaptures(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));

        $scope = Scope::at(self::closureWithUses($ast), $ast);

        $captured = self::useNames($scope);
        self::assertContains('captured', $captured, 'use($captured) should be exposed as a capture');
        self::assertNotContains('notCaptured', $captured);
        self::assertEquals(
            self::className('Fixtures\Utility\ScopePatterns'),
            $scope->getSelfName(),
            'Closure inherits class context',
        );
    }

    public function testAnArrowFunctionHasNoStatements(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));

        $scope = Scope::at(self::arrowFunction($ast), $ast);

        self::assertSame([], $scope->getStatements(), 'Arrow function body is an expression, not statements');
    }

    public function testAPropertyHookWithABlockBodyHasItsStatements(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TopLevel/this_binding.php'));
        $hook = self::findFirst(Node\PropertyHook::class, $ast, fn ($n) => is_array($n->body));

        self::assertSame($hook->body, Scope::at($hook, $ast)->getStatements(), 'the statements of `get { … }`');
    }

    public function testAPropertyHookWithAnExpressionBodyHasNoStatements(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TopLevel/this_binding.php'));
        $hook = self::findFirst(Node\PropertyHook::class, $ast, fn ($n) => !is_array($n->body));

        self::assertSame([], Scope::at($hook, $ast)->getStatements(), '`get => …` is an expression, not statements');
    }

    public function testOnlyAnArrowFunctionCapturesImplicitly(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));

        self::assertTrue(
            Scope::at(self::arrowFunction($ast), $ast)->allowsImplicitCapture(),
            'fn () => $x reads $x without a use clause',
        );
        self::assertFalse(
            Scope::at(self::closureWithUses($ast), $ast)->allowsImplicitCapture(),
            'a closure sees only what its use clause names',
        );
        self::assertFalse(
            Scope::at(self::method('methodWithThis', $ast), $ast)->allowsImplicitCapture(),
            'a method sees none of the enclosing variables',
        );
    }

    /**
     * @param class-string<Node> $kind
     */
    #[DataProvider('innermostFunctionLikes')]
    public function testANodeIsInItsInnermostFunctionLike(string $fixture, string $variable, string $kind): void
    {
        $ast = self::parseWithParents($this->loadFixture($fixture));
        $node = self::findVariableNode($variable, $ast);
        self::assertNotNull($node);

        self::assertInstanceOf($kind, Scope::at($node, $ast)->getSourceNode(), "\${$variable} is in a $kind");
    }

    /**
     * @return array<string, array{string, string, class-string<Node>}>
     */
    public static function innermostFunctionLikes(): array
    {
        return [
            'method' => ['src/Utility/ScopePatterns.php', 'this', Stmt\ClassMethod::class],
            'function' => ['src/Utility/GlobalScope.php', 'functionVar', Stmt\Function_::class],
            'closure' => ['src/Utility/ScopePatterns.php', 'closureVar', Closure::class],
            'arrow function' => ['src/Utility/ScopePatterns.php', 'arrowVar', ArrowFunction::class],
        ];
    }

    public function testAFunctionLikeIsInItsOwnScope(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));
        $method = self::method('methodWithThis', $ast);

        self::assertSame($method, Scope::at($method, $ast)->getSourceNode(), 'a method\'s header is in the method');
    }

    /**
     * @param class-string<Stmt\ClassLike> $kind
     */
    #[DataProvider('classLikeKinds')]
    public function testEveryClassLikeKindIsAClassContext(string $fixture, string $kind, string $expected): void
    {
        $ast = self::parseWithParents($this->loadFixture($fixture));
        $classLike = self::findFirst($kind, $ast);

        self::assertEquals(
            self::className($expected),
            Scope::at($classLike, $ast)->getSelfName(),
            "a $kind is a class context",
        );
    }

    /**
     * @return array<string, array{string, class-string<Stmt\ClassLike>, string}>
     */
    public static function classLikeKinds(): array
    {
        return [
            'class' => ['src/Utility/ScopePatterns.php', Stmt\Class_::class, 'Fixtures\Utility\ScopePatterns'],
            'interface' => ['src/Domain/Entity.php', Stmt\Interface_::class, 'Fixtures\Domain\Entity'],
            'trait' => ['src/Traits/HasTimestamps.php', Stmt\Trait_::class, 'Fixtures\Traits\HasTimestamps'],
            'enum' => ['src/Enum/Status.php', Stmt\Enum_::class, 'Fixtures\Enum\Status'],
        ];
    }

    public function testAClassBodyOutsideAMethodIsGlobalWithItsClassContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));

        $scope = Scope::at(self::findFirst(Stmt\Class_::class, $ast), $ast);

        self::assertNull($scope->getSourceNode(), 'no function-like holds the class body');
        self::assertEquals(
            self::className('Fixtures\Utility\ScopePatterns'),
            $scope->getSelfName(),
            'self names the class',
        );
    }

    public function testAnAnonymousClassHasNoClassName(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/AnonymousClassScope.php'));
        $this_ = self::findVariableNode('this', $ast);
        self::assertNotNull($this_);

        self::assertNull(Scope::at($this_, $ast)->getSelfName(), 'an anonymous class has no name to resolve');
    }

    public function testAClassOutsideANamespaceIsNamedByItsShortName(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TypeInference/GlobalFunction.php'));
        $this_ = self::findVariableNode('this', $ast);
        self::assertNotNull($this_);

        self::assertEquals(self::className('GlobalConfig'), Scope::at($this_, $ast)->getSelfName());
    }

    public function testANodeAtNamespaceLevelIsInItsNamespacesStatements(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/GlobalScope.php'));
        $globalVar = self::findVariableNode('globalVar', $ast);
        self::assertNotNull($globalVar);

        $scope = Scope::at($globalVar, $ast);

        self::assertNull($scope->getSourceNode(), 'outside any function-like the scope is global');
        self::assertNull($scope->getSelfName(), 'no class holds it');
        $namespace = self::findFirst(Stmt\Namespace_::class, $ast);
        self::assertSame($namespace->stmts, $scope->getStatements(), 'the namespace\'s statements, not the file\'s');
    }

    public function testANodeAtFileLevelIsInTheFilesStatements(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TopLevel/global_scope_hover.php'));
        $activeUser = self::findVariableNode('activeUser', $ast);
        self::assertNotNull($activeUser);

        self::assertSame($ast, Scope::at($activeUser, $ast)->getStatements());
    }

    public function testNoNodeIsTheFilesGlobalScope(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TopLevel/global_scope_hover.php'));

        $scope = Scope::at(null, $ast);

        self::assertSame($ast, $scope->getStatements(), 'the file\'s own statements');
        self::assertSame([], $scope->getParams());
        self::assertSame([], $scope->getUses(), 'Global scope declares no closure captures');
        self::assertNull($scope->getThisType());
        self::assertNull($scope->getSourceNode());
    }

    public function testTheOuterScopeOfAClosureIsWhatHoldsIt(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));

        $outer = Scope::at(self::closureWithUses($ast), $ast)->outer();

        self::assertSame(self::method('methodWithClosureUse', $ast), $outer?->getSourceNode(), 'the method around it');
    }

    public function testAGlobalScopeHasNoOuterScope(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/GlobalScope.php'));

        self::assertNull(Scope::at(null, $ast)->outer(), 'nothing holds the file');
    }

    public function testTheNameContextIsTheEnclosingNamespaces(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Domain/User.php'));
        $this_ = self::findVariableNode('this', $ast);
        self::assertNotNull($this_);

        self::assertSame('Fixtures\Domain', Scope::at($this_, $ast)->nameContext()->namespace);
    }

    public function testTheNameContextOutsideANamespaceIsGlobal(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TypeInference/GlobalFunction.php'));
        $this_ = self::findVariableNode('this', $ast);
        self::assertNotNull($this_);

        self::assertSame('', Scope::at($this_, $ast)->nameContext()->namespace);
    }

    /**
     * @return iterable<string, array{string, NameContext}>
     */
    public static function nameContexts(): iterable
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

    #[DataProvider('nameContexts')]
    public function testTheNameContextHoldsTheImportsInEffectInItsNamespace(
        string $fixture,
        NameContext $expected,
    ): void {
        $document = new TextDocument('file:///t.php', 'php', 0, $this->loadFixture($fixture));
        $ast = ParserOnlySyntaxSource::create()->parse($document)->tree;
        $namespace = array_find(
            $ast,
            static fn (Stmt $stmt): bool => $stmt instanceof Stmt\Namespace_
                && $stmt->name?->toString() === $expected->namespace,
        );

        self::assertEquals(
            $expected,
            Scope::at($namespace, $ast)->nameContext(),
            'the block\'s own imports, each in the table for its kind',
        );
    }

    #[DataProvider('classNames')]
    public function testAClassNameResolvesInTheScope(string $fixture, string $name, ?string $expected): void
    {
        $ast = self::parseWithParents($this->loadFixture($fixture));
        $node = self::findFirst(Node\Name::class, $ast, fn ($n) => $n->toString() === $name);

        self::assertEquals(
            $expected === null ? null : ClasslikeName::fromFullyQualified($expected),
            Scope::at($node, $ast)->resolveClasslikeName($node),
            "$name in $fixture",
        );
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function classNames(): array
    {
        return [
            'self' => ['src/TypeInference/NewKeywords.php', 'self', 'Fixtures\TypeInference\NewKeywords'],
            'static' => ['src/TypeInference/NewKeywords.php', 'static', 'Fixtures\TypeInference\NewKeywords'],
            'parent' => ['src/TypeInference/NewKeywords.php', 'parent', 'Fixtures\Inheritance\ParentClass'],
            'a named class' => [
                'src/TypeInference/NewKeywords.php',
                'Fixtures\Inheritance\ParentClass',
                'Fixtures\Inheritance\ParentClass',
            ],
            'self outside a class' => ['src/TypeInference/StaticCallOutsideClass.php', 'self', null],
            'parent with no extends' => ['src/TypeInference/ParentWithoutExtends.php', 'parent', null],
            'parent in an anonymous class' => [
                'src/Resolution/AnonymousParent.php',
                'parent',
                'Fixtures\Inheritance\ParentClass',
            ],
        ];
    }

    /**
     * @return iterable<string, array{string, class-string<Stmt\ClassMethod|Stmt\Function_>, string, LateBinding}>
     */
    public static function lateBindings(): iterable
    {
        $parentClass = ClasslikeName::fromFullyQualified('Fixtures\Inheritance\ParentClass');
        $grandparent = ClasslikeName::fromFullyQualified('Fixtures\Inheritance\Grandparent');
        $trait = ClasslikeName::fromFullyQualified('Fixtures\Traits\HasTimestamps');
        yield 'a class with a parent' => [
            'src/Inheritance/ParentClass.php',
            Stmt\ClassMethod::class,
            'parentMethod',
            new LateBinding($parentClass, $parentClass, $grandparent),
        ];
        yield 'a trait, which extends nothing' => [
            'src/Traits/HasTimestamps.php',
            Stmt\ClassMethod::class,
            'getCreatedAt',
            new LateBinding($trait, $trait, null),
        ];
        yield 'an anonymous class, which has a parent but no name' => [
            'src/Resolution/AnonymousParent.php',
            Stmt\ClassMethod::class,
            'call',
            new LateBinding(null, null, $parentClass),
        ];
        yield 'a free function' => [
            'src/Utility/GlobalScope.php',
            Stmt\Function_::class,
            'utilityFunction',
            new LateBinding(null, null, null),
        ];
    }

    /**
     * @param class-string<Stmt\ClassMethod|Stmt\Function_> $kind
     */
    #[DataProvider('lateBindings')]
    public function testTheLateBindingNamesTheEnclosingClass(
        string $fixture,
        string $kind,
        string $function,
        LateBinding $expected,
    ): void {
        $ast = self::parseWithParents($this->loadFixture($fixture));
        $node = self::findFirst($kind, $ast, fn ($n) => $n->name->toString() === $function);

        self::assertEquals(
            $expected,
            Scope::at($node, $ast)->lateBinding(),
            'static and self name the class written in, parent what it extends',
        );
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function forwardedStatics(): array
    {
        return [
            'parent:: passes on the class written in' => ['parent', 'Fixtures\TypeInference\NewKeywords'],
            'self:: is the class looked up anyway' => ['self', null],
            'a named class' => ['Fixtures\Inheritance\ParentClass', null],
        ];
    }

    /**
     * A `parent::` access forwards late static binding (PHP manual, Late
     * Static Bindings), so `static` names the class the access is written in.
     */
    #[DataProvider('forwardedStatics')]
    public function testOnlyAParentAccessPassesOnTheClassItIsWrittenIn(string $receiver, ?string $expected): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/TypeInference/NewKeywords.php'));
        $node = self::findFirst(Node\Name::class, $ast, fn ($n) => $n->toString() === $receiver);

        self::assertSame(
            $expected,
            Scope::at($node, $ast)->staticFor($node)?->qualifiedName->fullyQualifiedName(),
            "static for a member on {$receiver}",
        );
    }

    private static function className(string $fullyQualifiedName): ClasslikeName
    {
        return ClasslikeName::fromFullyQualified($fullyQualifiedName);
    }

    /**
     * @return list<string>
     */
    private static function useNames(Scope $scope): array
    {
        $names = [];
        foreach ($scope->getUses() as $use) {
            if (is_string($use->var->name)) {
                $names[] = $use->var->name;
            }
        }
        return $names;
    }

    /**
     * @template T of Node
     * @param class-string<T> $kind
     * @param array<Stmt> $ast
     * @param ?callable(T): bool $matches
     * @return T
     */
    private static function findFirst(string $kind, array $ast, ?callable $matches = null): Node
    {
        $node = (new NodeFinder())->findFirst(
            $ast,
            fn (Node $n) => $n instanceof $kind && ($matches === null || $matches($n)),
        );
        self::assertInstanceOf($kind, $node, "no $kind found");
        return $node;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function method(string $name, array $ast): Stmt\ClassMethod
    {
        return self::findFirst(Stmt\ClassMethod::class, $ast, fn ($n) => $n->name->toString() === $name);
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function closureWithUses(array $ast): Closure
    {
        return self::findFirst(Closure::class, $ast, fn ($n) => $n->uses !== []);
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function arrowFunction(array $ast): ArrowFunction
    {
        return self::findFirst(ArrowFunction::class, $ast);
    }
}
