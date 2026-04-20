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
}
