<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ByteOffset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ByteOffset::class)]
final class ByteOffsetTest extends TestCase
{
    public function testConstruction(): void
    {
        self::assertSame(12, (new ByteOffset(12))->value, 'the offset keeps its value');
    }
}
