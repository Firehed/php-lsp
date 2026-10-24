<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Resolution\Scope;
use Firehed\PhpLsp\Resolution\VariableBinding;
use PhpParser\Node\Expr\Variable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariableBinding::class)]
final class VariableBindingTest extends TestCase
{
    public function testKeepsTheNameTheBindingNodeAndItsScope(): void
    {
        $node = new Variable('user');
        $scope = Scope::at(null, []);
        $binding = new VariableBinding('user', $node, $scope);

        self::assertSame('user', $binding->name, 'the name is kept');
        self::assertSame($node, $binding->node, 'the node to point at is kept');
        self::assertSame($scope, $binding->scope, 'the scope it binds in is kept');
        self::assertNull($binding->type, 'code that binds it gives its type');
    }

    public function testABindingPhpMakesKeepsTheTypePhpGivesIt(): void
    {
        $type = new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\ThisBinding\ThisBinding'));

        $binding = new VariableBinding('this', null, Scope::at(null, []), $type);

        self::assertNull($binding->node, 'no code binds it');
        self::assertSame($type, $binding->type, 'the type PHP gives it is kept');
    }
}
