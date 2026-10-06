<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\EnumImplicits;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\UnionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnumImplicits::class)]
final class EnumImplicitsTest extends TestCase
{
    public function testAUnitEnumOffersOnlyCasesAndName(): void
    {
        $enum = self::enum();

        self::assertSame(
            ['cases'],
            array_keys(EnumImplicits::methods($enum, null)),
            'a unit enum has no value to convert',
        );
        self::assertSame(['name'], array_keys(EnumImplicits::properties($enum, null)), 'a unit case has no value');
    }

    /**
     * @return iterable<string, array{PrimitiveType}>
     */
    public static function backingTypes(): iterable
    {
        yield 'int' => [new PrimitiveType('int')];
        yield 'string' => [new PrimitiveType('string')];
    }

    #[DataProvider('backingTypes')]
    public function testABackedEnumConvertsFromAndExposesItsBackingType(PrimitiveType $backing): void
    {
        $enum = self::enum();
        $methods = EnumImplicits::methods($enum, $backing);
        $properties = EnumImplicits::properties($enum, $backing);

        self::assertSame(['cases', 'from', 'tryFrom'], array_keys($methods), 'a backed enum converts from a value');
        self::assertEquals($backing, $methods['from']->parameters[0]->type, 'from() takes the backing type');
        self::assertEquals($backing, $methods['tryFrom']->parameters[0]->type, 'tryFrom() takes the backing type');
        self::assertEquals(new ClasslikeType($enum), $methods['from']->returnType, 'from() returns a case');
        self::assertEquals(
            new UnionType([new ClasslikeType($enum), new PrimitiveType('null')]),
            $methods['tryFrom']->returnType,
            'tryFrom() returns a case or null',
        );
        self::assertSame(['name', 'value'], array_keys($properties), 'a backed case exposes its value');
        self::assertEquals($backing, $properties['value']->type, 'the value has the backing type');
    }

    private static function enum(): ClasslikeName
    {
        return ClasslikeName::fromFullyQualified('Fixtures\Status');
    }
}
