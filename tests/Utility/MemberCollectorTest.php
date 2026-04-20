<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Completion\MemberFilter;
use Firehed\PhpLsp\Completion\VisibilityFilter;
use Firehed\PhpLsp\Utility\MemberCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberCollector::class)]
class MemberCollectorTest extends TestCase
{
    use AstTestHelperTrait;

    public function testCollectsAllInstanceMembers(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public string $name;
    protected int $age;
    private string $password;

    public function getName(): string { return $this->name; }
    protected function getAge(): int { return $this->age; }
    private function getPassword(): string { return $this->password; }

    public static string $count;
    public static function getCount(): int { return 0; }
}
PHP;
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('User', $ast, VisibilityFilter::All, MemberFilter::Instance);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        // Should include all instance members
        self::assertContains('getName', $methodNames);
        self::assertContains('getAge', $methodNames);
        self::assertContains('getPassword', $methodNames);
        self::assertContains('name', $propertyNames);
        self::assertContains('age', $propertyNames);
        self::assertContains('password', $propertyNames);

        // Should exclude static members
        self::assertNotContains('getCount', $methodNames);
        self::assertNotContains('count', $propertyNames);
    }

    public function testCollectsPublicOnlyMembers(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public string $name;
    protected int $age;
    private string $password;

    public function getName(): string { return $this->name; }
    protected function getAge(): int { return $this->age; }
    private function getPassword(): string { return $this->password; }
}
PHP;
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('User', $ast, VisibilityFilter::PublicOnly, MemberFilter::Instance);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        // Should include only public
        self::assertContains('getName', $methodNames);
        self::assertContains('name', $propertyNames);

        // Should exclude protected and private
        self::assertNotContains('getAge', $methodNames);
        self::assertNotContains('getPassword', $methodNames);
        self::assertNotContains('age', $propertyNames);
        self::assertNotContains('password', $propertyNames);
    }

    public function testCollectsPublicProtectedMembers(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public string $name;
    protected int $age;
    private string $password;

    public function getName(): string { return $this->name; }
    protected function getAge(): int { return $this->age; }
    private function getPassword(): string { return $this->password; }
}
PHP;
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('User', $ast, VisibilityFilter::PublicProtected, MemberFilter::Instance);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        // Should include public and protected
        self::assertContains('getName', $methodNames);
        self::assertContains('getAge', $methodNames);
        self::assertContains('name', $propertyNames);
        self::assertContains('age', $propertyNames);

        // Should exclude private
        self::assertNotContains('getPassword', $methodNames);
        self::assertNotContains('password', $propertyNames);
    }

    public function testCollectsStaticMembers(): void
    {
        $code = <<<'PHP'
<?php
class Counter
{
    public static int $count = 0;
    private static string $internalState;

    public static function increment(): void { self::$count++; }
    private static function reset(): void { self::$count = 0; }

    public int $instanceProp;
    public function instanceMethod(): void {}
}
PHP;
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('Counter', $ast, VisibilityFilter::All, MemberFilter::Static);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        // Should include all static members
        self::assertContains('increment', $methodNames);
        self::assertContains('reset', $methodNames);
        self::assertContains('count', $propertyNames);
        self::assertContains('internalState', $propertyNames);

        // Should exclude instance members
        self::assertNotContains('instanceMethod', $methodNames);
        self::assertNotContains('instanceProp', $propertyNames);
    }

    public function testCollectsBothStaticAndInstanceMembers(): void
    {
        $code = <<<'PHP'
<?php
class Mixed
{
    public string $instanceProp;
    public static int $staticProp;

    public function instanceMethod(): void {}
    public static function staticMethod(): void {}
}
PHP;
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('Mixed', $ast, VisibilityFilter::All, MemberFilter::Both);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        // Should include both
        self::assertContains('instanceMethod', $methodNames);
        self::assertContains('staticMethod', $methodNames);
        self::assertContains('instanceProp', $propertyNames);
        self::assertContains('staticProp', $propertyNames);
    }

    public function testCollectsConstants(): void
    {
        $code = <<<'PHP'
<?php
class Config
{
    public const VERSION = '1.0';
    private const SECRET = 'abc';
}
PHP;
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('Config', $ast, VisibilityFilter::All, MemberFilter::Static);

        $constantNames = array_column($members['constants'], 'name');

        self::assertContains('VERSION', $constantNames);
        self::assertContains('SECRET', $constantNames);
    }

    public function testCollectsEnumCases(): void
    {
        $code = <<<'PHP'
<?php
enum Status
{
    case Active;
    case Inactive;
}
PHP;
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('Status', $ast, VisibilityFilter::All, MemberFilter::Static);

        $caseNames = array_column($members['enumCases'], 'name');

        self::assertContains('Active', $caseNames);
        self::assertContains('Inactive', $caseNames);
    }

    public function testReturnsEmptyForUnknownClass(): void
    {
        $code = '<?php class Foo {}';
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('UnknownClass', $ast, VisibilityFilter::All, MemberFilter::Instance);

        self::assertEmpty($members['methods']);
        self::assertEmpty($members['properties']);
        self::assertEmpty($members['constants']);
        self::assertEmpty($members['enumCases']);
    }

    public function testCollectsNamespacedClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

class User
{
    public string $name;
    public function getName(): string { return $this->name; }
}
PHP;
        $ast = self::parseWithParents($code);

        $collector = new MemberCollector();
        $members = $collector->collect('App\\Models\\User', $ast, VisibilityFilter::All, MemberFilter::Instance);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        self::assertContains('getName', $methodNames);
        self::assertContains('name', $propertyNames);
    }
}
