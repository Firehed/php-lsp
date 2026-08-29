<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Resolution\ResolvedTypeOnly;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ResolvedTypeOnly::class)]
final class ResolvedTypeOnlyTest extends TestCase
{
    public function testHasNoDefinitionLocation(): void
    {
        $resolved = new ResolvedTypeOnly(TypeFactory::className(stdClass::class));
        self::assertNull($resolved->getDefinitionLocation(), 'type-only symbols have no persistent site to point at');
    }

    public function testHasNoDocumentation(): void
    {
        $resolved = new ResolvedTypeOnly(TypeFactory::className(stdClass::class));
        self::assertNull($resolved->getDocumentation(), 'type-only symbols carry no docblock');
    }

    public function testGetTypeReturnsWrappedType(): void
    {
        $type = TypeFactory::className(stdClass::class);
        self::assertSame($type, (new ResolvedTypeOnly($type))->getType());
    }

    public function testFormatDelegatesToType(): void
    {
        $type = TypeFactory::className(stdClass::class);
        self::assertSame($type->format(), (new ResolvedTypeOnly($type))->format());
    }
}
