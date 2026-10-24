<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\Scope;
use Firehed\PhpLsp\Resolution\VariableBinding;
use Firehed\PhpLsp\Resolution\VariableBindings;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariableBindings::class)]
class VariableBindingsTest extends TestCase
{
    use AstTestHelperTrait;
    use LoadsFixturesTrait;

    public function testEnumeratesParamsUsesAndAssignmentsInOrder(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BindingSites.php'));
        $method = self::findMethodByName('method', $ast);
        $scope = Scope::at($method, $ast);
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
        $scope = Scope::at($method, $ast);

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
        $scope = Scope::at($closure, $ast);

        $names = self::namesOf(VariableBindings::before($scope, $closure->getEndFilePos()));

        self::assertContains('closureParam', $names, 'Closure param is a binding');
        self::assertContains('assigned', $names, 'use ($assigned) is a binding inside the closure');
        self::assertContains('insideClosure', $names, 'Assignment inside the closure is a binding');
    }

    public function testEnumeratesBindingsInFinallyElseIfAndSwitch(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BranchingBindingSites.php'));
        $method = self::findMethodByName('method', $ast);
        $scope = Scope::at($method, $ast);

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
        $scope = Scope::at($arrow, $ast);

        $names = self::namesOf(VariableBindings::before($scope, $arrow->getEndFilePos()));

        self::assertSame(['arrowParam'], $names, 'Arrow scope is its params; fall-through is a caller concern');
    }

    public function testAnArrowFunctionSeesWhatIsBoundBeforeIt(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BindingSites.php'));
        $arrow = self::findFirst($ast, fn (Node $n) => $n instanceof Node\Expr\ArrowFunction);
        self::assertInstanceOf(Node\Expr\ArrowFunction::class, $arrow);

        $visible = VariableBindings::visible(Scope::at($arrow, $ast), $arrow->getEndFilePos());

        self::assertArrayHasKey('arrowParam', $visible, 'its own parameter');
        self::assertArrayHasKey('param', $visible, 'a parameter of the method around it');
        self::assertArrayHasKey('assigned', $visible, 'an assignment before it in the method around it');
        self::assertArrayNotHasKey('afterEverything', $visible, 'nothing bound after the arrow function');
        self::assertArrayNotHasKey('insideClosure', $visible, 'nothing inside a sibling closure');
    }

    public function testALongClosureSeesOnlyWhatItCaptures(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BindingSites.php'));
        $closure = self::findFirst($ast, fn (Node $n) => $n instanceof Node\Expr\Closure);
        self::assertInstanceOf(Node\Expr\Closure::class, $closure);

        $visible = VariableBindings::visible(Scope::at($closure, $ast), $closure->getEndFilePos());

        self::assertSame(
            ['this', 'closureParam', 'assigned', 'insideClosure'],
            array_keys($visible),
            'no fall-through; $this, which the closure inherits, is all it sees without a use clause',
        );
    }

    public function testAnInnerBindingShadowsAnOuterOne(): void
    {
        $ast = self::parseWithParents($this->loadFixture('src/Utility/BindingSites.php'));
        $method = self::findMethodByName('shadowing', $ast);
        $arrow = self::findFirst([$method], fn (Node $n) => $n instanceof Node\Expr\ArrowFunction);
        self::assertInstanceOf(Node\Expr\ArrowFunction::class, $arrow);

        $visible = VariableBindings::visible(Scope::at($arrow, $ast), $arrow->getEndFilePos());

        self::assertSame($arrow->params[0]->var, $visible['value']->node ?? null, 'the arrow function\'s own $value');
        self::assertSame($arrow, $visible['value']->scope->getSourceNode(), 'bound in the arrow function\'s scope');
    }

    public function testThisIsBoundWherePhpBindsIt(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TopLevel/this_binding.php'));
        $method = self::findMethodByName('instanceMethod', $ast);
        $scope = Scope::at($method, $ast);

        $visible = VariableBindings::visible($scope, $method->getStartFilePos());

        self::assertSame(['this'], array_keys($visible), 'an instance method binds $this before any code runs');
        self::assertNull($visible['this']->node, 'no code binds it');
        self::assertSame($scope, $visible['this']->scope, 'bound in the method\'s scope');
    }

    public function testCodeNeverBindsThis(): void
    {
        $ast = self::parseWithParents($this->loadFixture('TopLevel/this_binding.php'));
        $method = self::findMethodByName('rebindsThis', $ast);
        $closure = self::findFirst([$method], fn (Node $n) => $n instanceof Node\Expr\Closure);
        self::assertInstanceOf(Node\Expr\Closure::class, $closure);

        self::assertSame(
            ['items', 'closure'],
            array_keys(VariableBindings::visible(Scope::at($method, $ast), $method->getEndFilePos())),
            'an assignment, foreach, or catch naming $this binds nothing',
        );
        self::assertSame(
            [],
            array_keys(VariableBindings::visible(Scope::at($closure, $ast), $closure->getEndFilePos())),
            'a parameter or use clause naming $this binds nothing',
        );
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
