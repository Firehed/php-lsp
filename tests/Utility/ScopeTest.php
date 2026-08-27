<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Utility\Scope;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Scope::class)]
class ScopeTest extends TestCase
{
    use AstTestHelperTrait;
    use LoadsFixturesTrait;

    public function testForNodeFreeFunctionHasNoClassContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/GlobalScope.php'));
        $function = self::findFunction('utilityFunction', $ast);

        $scope = Scope::forNode($function);

        self::assertSame([], $scope->getParams(), 'Free function fixture declares no parameters');
        self::assertNull($scope->getSelfContext(), 'Free function has no enclosing class');
        self::assertNull($scope->getParentContext());
        self::assertNull($scope->getThisType(), '$this is not bound in a free function');
        self::assertNotEmpty($scope->getStatements(), 'Function body statements should be exposed');
    }

    public function testForNodeClassMethodBindsThisAndSelf(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));
        $method = self::findMethod('methodWithThis', $ast);

        $scope = Scope::forNode($method);

        self::assertSame('Fixtures\Utility\ScopePatterns', $scope->getSelfContext());
        self::assertNull($scope->getParentContext(), 'ScopePatterns has no parent class');
        self::assertSame('Fixtures\Utility\ScopePatterns', $scope->getThisType()?->format());
    }

    public function testForNodeChildMethodResolvesParentContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Inheritance/ParentClass.php'));
        $method = self::findMethod('parentMethod', $ast);

        $scope = Scope::forNode($method);

        self::assertSame('Fixtures\Inheritance\ParentClass', $scope->getSelfContext());
        self::assertSame('Fixtures\Inheritance\Grandparent', $scope->getParentContext());
    }

    public function testForNodeTraitMethodHasNoParentContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Traits/HasTimestamps.php'));
        $method = self::findMethod('getCreatedAt', $ast);

        $scope = Scope::forNode($method);

        self::assertSame('Fixtures\Traits\HasTimestamps', $scope->getSelfContext());
        self::assertNull($scope->getParentContext(), 'A trait has no extends clause');
        self::assertSame('Fixtures\Traits\HasTimestamps', $scope->getThisType()?->format());
    }

    public function testForNodeClosureExposesUseCaptures(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));
        $closure = self::findClosureWithUses($ast);

        $scope = Scope::forNode($closure);

        self::assertTrue($scope->capturesVariable('captured'), 'use($captured) should be a capture');
        self::assertFalse($scope->capturesVariable('notCaptured'));
        self::assertNull($scope->getThisType(), 'A closure is not a method, so $this is not added here');
        self::assertSame('Fixtures\Utility\ScopePatterns', $scope->getSelfContext(), 'Closure inherits class context');
    }

    public function testForNodeArrowFunctionHasNoStatements(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));
        $arrow = (new NodeFinder())->findFirstInstanceOf($ast, ArrowFunction::class);
        self::assertInstanceOf(ArrowFunction::class, $arrow);

        $scope = Scope::forNode($arrow);

        self::assertSame([], $scope->getStatements(), 'Arrow function body is an expression, not statements');
    }

    public function testForNodeCarriesEnclosingClassLike(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));
        $method = self::findMethod('methodWithThis', $ast);

        $scope = Scope::forNode($method);

        $classLike = $scope->getEnclosingClassLike();
        self::assertInstanceOf(Stmt\Class_::class, $classLike, 'forNode should carry enclosing class');
        self::assertSame('ScopePatterns', $classLike->name?->toString());
    }

    public function testForNodeFreeFunctionHasNullClassLike(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/GlobalScope.php'));
        $function = self::findFunction('utilityFunction', $ast);

        $scope = Scope::forNode($function);

        self::assertNull($scope->getEnclosingClassLike(), 'Free function has no enclosing class-like');
    }

    /**
     * @param class-string $expectedNodeClass
     */
    #[DataProvider('classLikeKindFixtures')]
    public function testAtOffsetCarriesAllClassLikeKinds(
        string $fixture,
        string $methodName,
        string $expectedNodeClass,
    ): void {
        $ast = self::parseWithParents($this->loadFixture($fixture));
        $method = self::findMethod($methodName, $ast);
        $offset = $method->getStartFilePos() + 1;

        $scope = Scope::atOffset($ast, $offset);

        self::assertInstanceOf(
            $expectedNodeClass,
            $scope->getEnclosingClassLike(),
            "atOffset should carry enclosing $expectedNodeClass",
        );
    }

    /**
     * @return array<string, array{string, string, class-string}>
     */
    public static function classLikeKindFixtures(): array
    {
        return [
            'class' => ['src/Utility/ScopePatterns.php', 'methodWithThis', Stmt\Class_::class],
            'trait' => ['src/Traits/HasTimestamps.php', 'getCreatedAt', Stmt\Trait_::class],
            'enum' => ['src/Enum/Status.php', 'label', Stmt\Enum_::class],
        ];
    }

    public function testAtOffsetCarriesEnclosingInterface(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Domain/Entity.php'));
        $method = (new NodeFinder())->findFirst(
            $ast,
            fn(Node $n) => $n instanceof Stmt\ClassMethod && $n->name->toString() === 'getId',
        );
        self::assertInstanceOf(Stmt\ClassMethod::class, $method);

        $scope = Scope::atOffset($ast, $method->getStartFilePos());

        self::assertInstanceOf(
            Stmt\Interface_::class,
            $scope->getEnclosingClassLike(),
            'atOffset should carry enclosing interface',
        );
    }

    public function testAtOffsetOutsideClassHasNullClassLike(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/GlobalScope.php'));
        $globalVar = self::findVariableNode('globalVar', $ast);
        self::assertNotNull($globalVar);

        $scope = Scope::atOffset($ast, $globalVar->getStartFilePos());

        self::assertNull($scope->getEnclosingClassLike(), 'Global scope outside class has no class-like');
    }

    public function testGlobalScopeHasNoBindings(): void
    {
        $scope = Scope::global([]);

        self::assertSame([], $scope->getParams());
        self::assertSame([], $scope->getStatements());
        self::assertNull($scope->getSelfContext());
        self::assertNull($scope->getParentContext());
        self::assertNull($scope->getThisType());
        self::assertFalse($scope->capturesVariable('anything'));
        self::assertNull($scope->getEnclosingClassLike(), 'Global scope has no class-like');
    }

    public function testAtOffsetReturnsEnclosingClosureNotMethod(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));
        $closure = self::findClosureWithUses($ast);
        $offset = $closure->stmts[0]->getStartFilePos();

        $scope = Scope::atOffset($ast, $offset);

        self::assertTrue(
            $scope->capturesVariable('captured'),
            'Innermost function-like node (the closure) should win over the enclosing method',
        );
    }

    public function testAtOffsetInNamespacedFileUsesNamespaceStatements(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/GlobalScope.php'));
        $globalVar = self::findVariableNode('globalVar', $ast);
        self::assertNotNull($globalVar);

        $scope = Scope::atOffset($ast, $globalVar->getStartFilePos());

        self::assertNull($scope->getSelfContext(), 'File-level code has no class context');
        self::assertNull($scope->getThisType());
        // The braceless namespace's statements (assignment + function declaration),
        // not the AST root (which holds only the Namespace_ node).
        $hasFunction = array_filter($scope->getStatements(), fn(Node $s) => $s instanceof Stmt\Function_);
        self::assertNotEmpty($hasFunction, 'Namespace-level statements should be exposed for global scope');
    }

    public function testAtOffsetInFilelessNamespaceUsesAstRoot(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TopLevel/global_scope_hover.php'));
        $activeUser = self::findVariableNode('activeUser', $ast);
        self::assertNotNull($activeUser);

        $scope = Scope::atOffset($ast, $activeUser->getStartFilePos());

        self::assertNull($scope->getSelfContext());
        self::assertSame($ast, $scope->getStatements(), 'Without a namespace, global statements are the AST root');
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findFunction(string $name, array $ast): Stmt\Function_
    {
        $node = (new NodeFinder())->findFirst(
            $ast,
            fn(Node $n) => $n instanceof Stmt\Function_ && $n->name->toString() === $name,
        );
        self::assertInstanceOf(Stmt\Function_::class, $node, "Function $name not found");
        return $node;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findMethod(string $name, array $ast): Stmt\ClassMethod
    {
        $node = (new NodeFinder())->findFirst(
            $ast,
            fn(Node $n) => $n instanceof Stmt\ClassMethod && $n->name->toString() === $name,
        );
        self::assertInstanceOf(Stmt\ClassMethod::class, $node, "Method $name not found");
        return $node;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findClosureWithUses(array $ast): Closure
    {
        $node = (new NodeFinder())->findFirst(
            $ast,
            fn(Node $n) => $n instanceof Closure && $n->uses !== [],
        );
        self::assertInstanceOf(Closure::class, $node, 'Closure with use() not found');
        return $node;
    }
}
