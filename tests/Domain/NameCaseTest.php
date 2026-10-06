<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\NameCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameCase::class)]
final class NameCaseTest extends TestCase
{
    public function testInsensitiveNamesAreKeyedInLowercase(): void
    {
        self::assertSame('app\widget', NameCase::Insensitive->normalize('App\Widget'), 'case does not matter');
    }

    public function testSensitiveNamesAreKeptAsWritten(): void
    {
        self::assertSame('App\WIDGET', NameCase::Sensitive->normalize('App\WIDGET'), 'case distinguishes them');
    }
}
