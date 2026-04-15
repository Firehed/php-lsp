<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Utility\ClassFinder;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassFinder::class)]
class ClassFinderTest extends TestCase
{
    private static function parse(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        return $parser->parse($code) ?? [];
    }

    public function testFindClassByShortName(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {}
PHP;
        $ast = self::parse($code);
        $result = ClassFinder::findInAst('MyClass', $ast);

        self::assertInstanceOf(Stmt\Class_::class, $result);
        self::assertSame('MyClass', $result->name->toString());
    }

    public function testFindClassByFqn(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;
class User {}
PHP;
        $ast = self::parse($code);
        $result = ClassFinder::findInAst('App\\Models\\User', $ast);

        self::assertInstanceOf(Stmt\Class_::class, $result);
        self::assertSame('User', $result->name->toString());
    }

    public function testFindInterface(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Contracts;
interface UserRepository {}
PHP;
        $ast = self::parse($code);
        $result = ClassFinder::findInAst('App\\Contracts\\UserRepository', $ast);

        self::assertInstanceOf(Stmt\Interface_::class, $result);
        self::assertSame('UserRepository', $result->name->toString());
    }

    public function testFindTrait(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Concerns;
trait HasTimestamps {}
PHP;
        $ast = self::parse($code);
        $result = ClassFinder::findInAst('App\\Concerns\\HasTimestamps', $ast);

        self::assertInstanceOf(Stmt\Trait_::class, $result);
        self::assertSame('HasTimestamps', $result->name->toString());
    }

    public function testFindEnum(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Enums;
enum Status: string {}
PHP;
        $ast = self::parse($code);
        $result = ClassFinder::findInAst('App\\Enums\\Status', $ast);

        self::assertInstanceOf(Stmt\Enum_::class, $result);
        self::assertSame('Status', $result->name->toString());
    }

    public function testReturnsNullWhenNotFound(): void
    {
        $code = <<<'PHP'
<?php
class Foo {}
PHP;
        $ast = self::parse($code);
        $result = ClassFinder::findInAst('Bar', $ast);

        self::assertNull($result);
    }

    public function testFindsClassInGlobalNamespace(): void
    {
        $code = <<<'PHP'
<?php
class GlobalClass {}
PHP;
        $ast = self::parse($code);
        $result = ClassFinder::findInAst('GlobalClass', $ast);

        self::assertInstanceOf(Stmt\Class_::class, $result);
    }

    public function testFindsFirstMatchingClass(): void
    {
        $code = <<<'PHP'
<?php
namespace First;
class Duplicate {}

namespace Second;
class Duplicate {}
PHP;
        $ast = self::parse($code);
        $result = ClassFinder::findInAst('First\\Duplicate', $ast);

        self::assertInstanceOf(Stmt\Class_::class, $result);
    }
}
