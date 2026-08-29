<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\VariableBinding;
use Firehed\PhpLsp\Utility\VariableBindings;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariableBindings::class)]
#[CoversClass(VariableBinding::class)]
class VariableBindingsTest extends TestCase
{
    use AstTestHelperTrait;
    use LoadsFixturesTrait;

    public function testEnumeratesParamsUsesAndAssignmentsInOrder(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BindingSites.php'));
        $method = self::findMethodByName('method', $ast);
        $scope = Scope::forNode($method);
        $endOfMethod = $method->getEndFilePos();

        $names = self::namesOf(VariableBindings::before($scope, $endOfMethod));

        $expected = [
            'param',
            'assigned',
            'key', 'value', 'inLoop',
            'inTry', 'caught', 'inCatch',
            'inIf', 'inElse',
            'closure', 'arrow',
            'afterEverything',
        ];
        self::assertSame($expected, $names, 'Params first, then bindings in source order; nested bodies excluded');
    }

    public function testExcludesBindingsAtOrAfterOffset(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BindingSites.php'));
        $method = self::findMethodByName('method', $ast);
        $scope = Scope::forNode($method);

        $target = self::findAssignmentTarget('assigned', $ast);
        $offset = $target->getStartFilePos();
        $names = self::namesOf(VariableBindings::before($scope, $offset));

        self::assertSame(['param'], $names, 'Only the param precedes the first assignment');
    }

    public function testIncludesLongClosureUseClauseBindings(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BindingSites.php'));
        $closure = self::findFirst($ast, fn (Node $n) => $n instanceof Node\Expr\Closure);
        self::assertInstanceOf(Node\Expr\Closure::class, $closure);
        $scope = Scope::forNode($closure);

        $names = self::namesOf(VariableBindings::before($scope, $closure->getEndFilePos()));

        self::assertContains('closureParam', $names, 'Closure param is a binding');
        self::assertContains('assigned', $names, 'use ($assigned) is a binding inside the closure');
        self::assertContains('insideClosure', $names, 'Assignment inside the closure is a binding');
    }

    public function testEnumeratesBindingsInFinallyElseIfAndSwitch(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BranchingBindingSites.php'));
        $method = self::findMethodByName('method', $ast);
        $scope = Scope::forNode($method);

        $names = self::namesOf(VariableBindings::before($scope, $method->getEndFilePos()));

        self::assertContains('inTry', $names, 'try body binding is enumerated');
        self::assertContains('inFinally', $names, 'finally body binding is enumerated');
        self::assertContains('inIf', $names, 'if body binding is enumerated');
        self::assertContains('inElseIf', $names, 'elseif body binding is enumerated');
        self::assertContains('inCase', $names, 'switch case body binding is enumerated');
    }

    public function testArrowFunctionScopeExposesOnlyItsParams(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BindingSites.php'));
        $arrow = self::findFirst($ast, fn (Node $n) => $n instanceof Node\Expr\ArrowFunction);
        self::assertInstanceOf(Node\Expr\ArrowFunction::class, $arrow);
        $scope = Scope::forNode($arrow);

        $names = self::namesOf(VariableBindings::before($scope, $arrow->getEndFilePos()));

        self::assertSame(['arrowParam'], $names, 'Arrow scope is its params; fall-through is a caller concern');
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findMethodByName(string $name, array $ast): Stmt\ClassMethod
    {
        $node = self::findFirst($ast, fn (Node $n) => $n instanceof Stmt\ClassMethod && $n->name->toString() === $name);
        self::assertInstanceOf(Stmt\ClassMethod::class, $node);
        return $node;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findAssignmentTarget(string $name, array $ast): Node\Expr\Variable
    {
        $node = self::findFirst($ast, function (Node $n) use ($name) {
            return $n instanceof Node\Expr\Assign
                && $n->var instanceof Node\Expr\Variable
                && $n->var->name === $name;
        });
        self::assertInstanceOf(Node\Expr\Assign::class, $node);
        self::assertInstanceOf(Node\Expr\Variable::class, $node->var);
        return $node->var;
    }

    /**
     * @param array<Stmt> $ast
     * @param callable(Node): bool $predicate
     */
    private static function findFirst(array $ast, callable $predicate): ?Node
    {
        return (new NodeFinder())->findFirst($ast, $predicate);
    }

    /**
     * @param list<VariableBinding> $bindings
     * @return list<string>
     */
    private static function namesOf(array $bindings): array
    {
        return array_map(fn (VariableBinding $b) => $b->name, $bindings);
    }
}
