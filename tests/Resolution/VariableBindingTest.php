<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

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
    }
}
