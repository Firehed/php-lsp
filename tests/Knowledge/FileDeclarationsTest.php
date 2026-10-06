<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\Declaration;
use Firehed\PhpLsp\Knowledge\FileDeclarations;
use PhpParser\Node\Const_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileDeclarations::class)]
final class FileDeclarationsTest extends TestCase
{
    public function testKeepsEachKindApart(): void
    {
        $class = new Declaration(QualifiedName::fromFullyQualified('App\Widget'), new Class_('Widget'));
        $function = new Declaration(QualifiedName::fromFullyQualified('App\make'), new Function_('make'));
        $constant = new Declaration(QualifiedName::fromFullyQualified('App\LIMIT'), new Const_('LIMIT', new Int_(1)));

        $declarations = new FileDeclarations([$class], [$function], [$constant]);

        self::assertSame([$class], $declarations->classLikes, 'class-likes are kept');
        self::assertSame([$function], $declarations->functions, 'functions are kept');
        self::assertSame([$constant], $declarations->constants, 'constants are kept');
    }
}
