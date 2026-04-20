<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\VisibilityFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(VisibilityFilter::class)]
class VisibilityFilterTest extends TestCase
{
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
}
