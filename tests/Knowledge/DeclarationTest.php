<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\Declaration;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Declaration::class)]
final class DeclarationTest extends TestCase
{
    public function testKeepsTheNameAndTheDeclaringNode(): void
    {
        $name = QualifiedName::fromFullyQualified('App\Widget');
        $node = new Class_('Widget');
        $declaration = new Declaration($name, $node);

        self::assertSame($name, $declaration->name, 'the name is kept');
        self::assertSame($node, $declaration->node, 'the declaring node is kept');
    }
}
