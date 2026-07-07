<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Utility\Scope;
use Fixtures\Inheritance\Grandparent;
use Fixtures\Inheritance\ParentClass;
use Fixtures\Traits\HasTimestamps;
use Fixtures\Utility\ScopePatterns;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
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

        self::assertSame(ScopePatterns::class, $scope->getSelfContext());
        self::assertNull($scope->getParentContext(), 'ScopePatterns has no parent class');
        self::assertEquals(new ClassName(ScopePatterns::class), $scope->getThisType());
    }

    public function testForNodeChildMethodResolvesParentContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Inheritance/ParentClass.php'));
        $method = self::findMethod('parentMethod', $ast);

        $scope = Scope::forNode($method);

        self::assertSame(ParentClass::class, $scope->getSelfContext());
        self::assertSame(Grandparent::class, $scope->getParentContext());
    }

    public function testForNodeTraitMethodHasNoParentContext(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Traits/HasTimestamps.php'));
        $method = self::findMethod('getCreatedAt', $ast);

        $scope = Scope::forNode($method);

        self::assertSame(HasTimestamps::class, $scope->getSelfContext());
        self::assertNull($scope->getParentContext(), 'A trait has no extends clause');
        self::assertEquals(new ClassName(HasTimestamps::class), $scope->getThisType());
    }

    public function testForNodeClosureExposesUseCaptures(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));
        $closure = self::findClosureWithUses($ast);

        $scope = Scope::forNode($closure);

        self::assertTrue($scope->capturesVariable('captured'), 'use($captured) should be a capture');
        self::assertFalse($scope->capturesVariable('notCaptured'));
        self::assertNull($scope->getThisType(), 'A closure is not a method, so $this is not added here');
        self::assertSame(ScopePatterns::class, $scope->getSelfContext(), 'Closure inherits enclosing class context');
    }

    public function testForNodeArrowFunctionHasNoStatements(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/ScopePatterns.php'));
        $arrow = (new NodeFinder())->findFirstInstanceOf($ast, ArrowFunction::class);
        self::assertInstanceOf(ArrowFunction::class, $arrow);

        $scope = Scope::forNode($arrow);

        self::assertSame([], $scope->getStatements(), 'Arrow function body is an expression, not statements');
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
