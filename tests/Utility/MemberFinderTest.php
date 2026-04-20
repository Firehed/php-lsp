<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Utility\MemberFinder;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberFinder::class)]
class MemberFinderTest extends TestCase
{
    private ParserService $parser;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
    }

    /**
     * @return array<Stmt>
     */
    private static function parse(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        return $parser->parse($code) ?? [];
    }

    public function testFindMethodInClass(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {}
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('MyClass', 'myMethod', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('myMethod', $result->name->toString());
    }

    public function testFindMethodReturnsNullWhenNotFound(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function otherMethod(): void {}
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('MyClass', 'notFound', $ast, null, $this->parser);

        self::assertNull($result);
    }

    public function testFindMethodInParentClass(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass {
    public function parentMethod(): void {}
}
class ChildClass extends ParentClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('ChildClass', 'parentMethod', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('parentMethod', $result->name->toString());
    }

    public function testFindMethodExcludesPrivateFromParent(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass {
    private function privateMethod(): void {}
}
class ChildClass extends ParentClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('ChildClass', 'privateMethod', $ast, null, $this->parser);

        self::assertNull($result);
    }

    public function testFindMethodIncludesProtectedFromParent(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass {
    protected function protectedMethod(): void {}
}
class ChildClass extends ParentClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('ChildClass', 'protectedMethod', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('protectedMethod', $result->name->toString());
    }

    public function testFindMethodInTrait(): void
    {
        $code = <<<'PHP'
<?php
trait MyTrait {
    public function traitMethod(): void {}
}
class MyClass {
    use MyTrait;
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('MyClass', 'traitMethod', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('traitMethod', $result->name->toString());
    }

    public function testFindMethodPrefersClassOverTrait(): void
    {
        $code = <<<'PHP'
<?php
trait MyTrait {
    public function overriddenMethod(): string { return 'trait'; }
}
class MyClass {
    use MyTrait;
    public function overriddenMethod(): string { return 'class'; }
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('MyClass', 'overriddenMethod', $ast, null, $this->parser);

        self::assertNotNull($result);
        // The class method should be found (has return type that differs)
        self::assertSame('overriddenMethod', $result->name->toString());
        // Should be from the class, not trait - class method is on line 8
        self::assertGreaterThan(6, $result->getStartLine());
    }

    public function testFindPropertyInClass(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public string $myProperty;
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findProperty('MyClass', 'myProperty', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertCount(1, $result->props);
        self::assertSame('myProperty', $result->props[0]->name->toString());
    }

    public function testFindPropertyReturnsNullWhenNotFound(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public string $otherProperty;
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findProperty('MyClass', 'notFound', $ast, null, $this->parser);

        self::assertNull($result);
    }

    public function testFindPropertyInParentClass(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass {
    public string $parentProperty;
}
class ChildClass extends ParentClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findProperty('ChildClass', 'parentProperty', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('parentProperty', $result->props[0]->name->toString());
    }

    public function testFindPropertyExcludesPrivateFromParent(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass {
    private string $privateProperty;
}
class ChildClass extends ParentClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findProperty('ChildClass', 'privateProperty', $ast, null, $this->parser);

        self::assertNull($result);
    }

    public function testFindMethodReturnsNullWhenClassNotFound(): void
    {
        $code = <<<'PHP'
<?php
class SomeClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('NonExistent', 'method', $ast, null, $this->parser);

        self::assertNull($result);
    }

    public function testFindMethodInNamespacedClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

class User {
    public function getName(): string {}
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('App\\Models\\User', 'getName', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('getName', $result->name->toString());
    }

    public function testFindPrivateMethodInSameClass(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    private function privateMethod(): void {}
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('MyClass', 'privateMethod', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('privateMethod', $result->name->toString());
    }

    public function testFindPrivatePropertyInSameClass(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    private string $privateProperty;
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findProperty('MyClass', 'privateProperty', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('privateProperty', $result->props[0]->name->toString());
    }

    public function testFindPropertyInTrait(): void
    {
        $code = <<<'PHP'
<?php
trait MyTrait {
    public string $traitProperty;
}
class MyClass {
    use MyTrait;
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findProperty('MyClass', 'traitProperty', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('traitProperty', $result->props[0]->name->toString());
    }

    public function testFindPropertyIncludesProtectedFromParent(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass {
    protected string $protectedProperty;
}
class ChildClass extends ParentClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findProperty('ChildClass', 'protectedProperty', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('protectedProperty', $result->props[0]->name->toString());
    }

    public function testFindMethodInInterface(): void
    {
        $code = <<<'PHP'
<?php
interface MyInterface {
    public function interfaceMethod(): void;
}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('MyInterface', 'interfaceMethod', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('interfaceMethod', $result->name->toString());
    }

    public function testFindMethodInGrandparent(): void
    {
        $code = <<<'PHP'
<?php
class GrandparentClass {
    public function grandparentMethod(): void {}
}
class ParentClass extends GrandparentClass {}
class ChildClass extends ParentClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findMethod('ChildClass', 'grandparentMethod', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('grandparentMethod', $result->name->toString());
    }

    public function testFindPropertyInGrandparent(): void
    {
        $code = <<<'PHP'
<?php
class GrandparentClass {
    public string $grandparentProperty;
}
class ParentClass extends GrandparentClass {}
class ChildClass extends ParentClass {}
PHP;
        $ast = self::parse($code);
        $result = MemberFinder::findProperty('ChildClass', 'grandparentProperty', $ast, null, $this->parser);

        self::assertNotNull($result);
        self::assertSame('grandparentProperty', $result->props[0]->name->toString());
    }
}
