<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\VisibilityFilter;
use Firehed\PhpLsp\Tests\Utility\AstTestHelperTrait;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(VisibilityFilter::class)]
class VisibilityFilterTest extends TestCase
{
    use AstTestHelperTrait;

    /**
     * @return array<string, array{VisibilityFilter, int}>
     * @codeCoverageIgnore
     */
    public static function methodFlagsProvider(): array
    {
        return [
            'All' => [
                VisibilityFilter::All,
                ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PRIVATE,
            ],
            'PublicOnly' => [
                VisibilityFilter::PublicOnly,
                ReflectionMethod::IS_PUBLIC,
            ],
            'PublicProtected' => [
                VisibilityFilter::PublicProtected,
                ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED,
            ],
        ];
    }

    #[DataProvider('methodFlagsProvider')]
    public function testGetMethodFlags(VisibilityFilter $filter, int $expected): void
    {
        self::assertSame($expected, $filter->getMethodFlags());
    }

    /**
     * @return array<string, array{VisibilityFilter, int}>
     * @codeCoverageIgnore
     */
    public static function propertyFlagsProvider(): array
    {
        return [
            'All' => [
                VisibilityFilter::All,
                ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE,
            ],
            'PublicOnly' => [
                VisibilityFilter::PublicOnly,
                ReflectionProperty::IS_PUBLIC,
            ],
            'PublicProtected' => [
                VisibilityFilter::PublicProtected,
                ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED,
            ],
        ];
    }

    #[DataProvider('propertyFlagsProvider')]
    public function testGetPropertyFlags(VisibilityFilter $filter, int $expected): void
    {
        self::assertSame($expected, $filter->getPropertyFlags());
    }

    /**
     * @return array<string, array{VisibilityFilter, int}>
     * @codeCoverageIgnore
     */
    public static function constantFlagsProvider(): array
    {
        return [
            'All' => [
                VisibilityFilter::All,
                ReflectionClassConstant::IS_PUBLIC
                    | ReflectionClassConstant::IS_PROTECTED
                    | ReflectionClassConstant::IS_PRIVATE,
            ],
            'PublicOnly' => [
                VisibilityFilter::PublicOnly,
                ReflectionClassConstant::IS_PUBLIC,
            ],
            'PublicProtected' => [
                VisibilityFilter::PublicProtected,
                ReflectionClassConstant::IS_PUBLIC | ReflectionClassConstant::IS_PROTECTED,
            ],
        ];
    }

    #[DataProvider('constantFlagsProvider')]
    public function testGetConstantFlags(VisibilityFilter $filter, int $expected): void
    {
        self::assertSame($expected, $filter->getConstantFlags());
    }

    /**
     * @return array<string, array{VisibilityFilter, string, bool}>
     * @codeCoverageIgnore
     */
    public static function allowsMethodProvider(): array
    {
        return [
            'All allows public' => [VisibilityFilter::All, 'public', true],
            'All allows protected' => [VisibilityFilter::All, 'protected', true],
            'All allows private' => [VisibilityFilter::All, 'private', true],
            'PublicOnly allows public' => [VisibilityFilter::PublicOnly, 'public', true],
            'PublicOnly denies protected' => [VisibilityFilter::PublicOnly, 'protected', false],
            'PublicOnly denies private' => [VisibilityFilter::PublicOnly, 'private', false],
            'PublicProtected allows public' => [VisibilityFilter::PublicProtected, 'public', true],
            'PublicProtected allows protected' => [VisibilityFilter::PublicProtected, 'protected', true],
            'PublicProtected denies private' => [VisibilityFilter::PublicProtected, 'private', false],
        ];
    }

    #[DataProvider('allowsMethodProvider')]
    public function testAllowsMethod(VisibilityFilter $filter, string $visibility, bool $expected): void
    {
        $method = $this->parseMethod("<?php class Foo { {$visibility} function bar() {} }");
        self::assertNotNull($method);
        self::assertSame($expected, $filter->allowsMethod($method));
    }

    /**
     * @return array<string, array{VisibilityFilter, string, bool}>
     * @codeCoverageIgnore
     */
    public static function allowsConstantProvider(): array
    {
        return [
            'All allows public' => [VisibilityFilter::All, 'public', true],
            'All allows protected' => [VisibilityFilter::All, 'protected', true],
            'All allows private' => [VisibilityFilter::All, 'private', true],
            'PublicOnly allows public' => [VisibilityFilter::PublicOnly, 'public', true],
            'PublicOnly denies protected' => [VisibilityFilter::PublicOnly, 'protected', false],
            'PublicOnly denies private' => [VisibilityFilter::PublicOnly, 'private', false],
            'PublicProtected allows public' => [VisibilityFilter::PublicProtected, 'public', true],
            'PublicProtected allows protected' => [VisibilityFilter::PublicProtected, 'protected', true],
            'PublicProtected denies private' => [VisibilityFilter::PublicProtected, 'private', false],
        ];
    }

    #[DataProvider('allowsConstantProvider')]
    public function testAllowsConstant(VisibilityFilter $filter, string $visibility, bool $expected): void
    {
        $const = $this->parseConstant("<?php class Foo { {$visibility} const BAR = 1; }");
        self::assertNotNull($const);
        self::assertSame($expected, $filter->allowsConstant($const));
    }

    public function testForClassAccessReturnsPublicOnlyWhenNoEnclosingClass(): void
    {
        self::assertSame(VisibilityFilter::PublicOnly, VisibilityFilter::forClassAccess(null, 'Target'));
    }

    public function testForClassAccessReturnsAllForSameClass(): void
    {
        $class = $this->parseClass('<?php class MyClass {}', 'MyClass');
        self::assertSame(VisibilityFilter::All, VisibilityFilter::forClassAccess($class, 'MyClass'));
    }

    public function testForClassAccessReturnsPublicProtectedForDirectSubclass(): void
    {
        $class = $this->parseClass('<?php class Child extends Parent_ {}', 'Child');
        self::assertSame(VisibilityFilter::PublicProtected, VisibilityFilter::forClassAccess($class, 'Parent_'));
    }

    public function testForClassAccessReturnsPublicOnlyForUnrelatedClass(): void
    {
        $class = $this->parseClass('<?php class Other {}', 'Other');
        self::assertSame(VisibilityFilter::PublicOnly, VisibilityFilter::forClassAccess($class, 'Target'));
    }

    public function testForClassAccessReturnsPublicProtectedForDeeperInheritance(): void
    {
        // InvalidArgumentException extends LogicException extends Exception
        // This tests the reflection isSubclassOf path
        $class = $this->parseClass('<?php class InvalidArgumentException {}', 'InvalidArgumentException');
        self::assertSame(VisibilityFilter::PublicProtected, VisibilityFilter::forClassAccess($class, 'Exception'));
    }

    private function parseClass(string $code, string $className): ?Stmt\Class_
    {
        $ast = self::parseWithParents($code);
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Class_ && $stmt->name?->toString() === $className) {
                return $stmt;
            }
        }
        return null;
    }

    private function parseMethod(string $code): ?Stmt\ClassMethod
    {
        $ast = self::parseWithParents($code);
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Class_) {
                foreach ($stmt->stmts as $member) {
                    if ($member instanceof Stmt\ClassMethod) {
                        return $member;
                    }
                }
            }
        }
        return null;
    }

    private function parseConstant(string $code): ?Stmt\ClassConst
    {
        $ast = self::parseWithParents($code);
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Class_) {
                foreach ($stmt->stmts as $member) {
                    if ($member instanceof Stmt\ClassConst) {
                        return $member;
                    }
                }
            }
        }
        return null;
    }
}
