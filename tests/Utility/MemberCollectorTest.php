<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Completion\MemberFilter;
use Firehed\PhpLsp\Completion\VisibilityFilter;
use Firehed\PhpLsp\Utility\MemberCollector;
use PhpParser\Node\Stmt;
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
        $classNode = self::findClassInCode($code, 'User');
        $members = MemberCollector::collect($classNode, VisibilityFilter::All, MemberFilter::Instance);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        self::assertContains('getName', $methodNames);
        self::assertContains('getAge', $methodNames);
        self::assertContains('getPassword', $methodNames);
        self::assertContains('name', $propertyNames);
        self::assertContains('age', $propertyNames);
        self::assertContains('password', $propertyNames);

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
        $classNode = self::findClassInCode($code, 'User');
        $members = MemberCollector::collect($classNode, VisibilityFilter::PublicOnly, MemberFilter::Instance);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        self::assertContains('getName', $methodNames);
        self::assertContains('name', $propertyNames);

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
        $classNode = self::findClassInCode($code, 'User');
        $members = MemberCollector::collect($classNode, VisibilityFilter::PublicProtected, MemberFilter::Instance);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        self::assertContains('getName', $methodNames);
        self::assertContains('getAge', $methodNames);
        self::assertContains('name', $propertyNames);
        self::assertContains('age', $propertyNames);

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
        $classNode = self::findClassInCode($code, 'Counter');
        $members = MemberCollector::collect($classNode, VisibilityFilter::All, MemberFilter::Static);

        $methodNames = array_column($members['methods'], 'name');
        $propertyNames = array_column($members['properties'], 'name');

        self::assertContains('increment', $methodNames);
        self::assertContains('reset', $methodNames);
        self::assertContains('count', $propertyNames);
        self::assertContains('internalState', $propertyNames);

        self::assertNotContains('instanceMethod', $methodNames);
        self::assertNotContains('instanceProp', $propertyNames);
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
        $classNode = self::findClassInCode($code, 'Config');
        $members = MemberCollector::collect($classNode, VisibilityFilter::All, MemberFilter::Static);

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
        $enumNode = self::findEnumInAst($ast, 'Status');
        $members = MemberCollector::collect($enumNode, VisibilityFilter::All, MemberFilter::Static);

        $caseNames = array_column($members['enumCases'], 'name');

        self::assertContains('Active', $caseNames);
        self::assertContains('Inactive', $caseNames);
    }

    public function testReturnsEmptyForNullNode(): void
    {
        $members = MemberCollector::collect(null, VisibilityFilter::All, MemberFilter::Instance);

        self::assertEmpty($members['methods']);
        self::assertEmpty($members['properties']);
        self::assertEmpty($members['constants']);
        self::assertEmpty($members['enumCases']);
    }

    private static function findClassInCode(string $code, string $className): ?Stmt\Class_
    {
        $ast = self::parseWithParents($code);
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Class_ && $stmt->name?->toString() === $className) {
                return $stmt;
            }
        }
        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findEnumInAst(array $ast, string $enumName): ?Stmt\Enum_
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Enum_ && $stmt->name?->toString() === $enumName) {
                return $stmt;
            }
        }
        return null;
    }
}
