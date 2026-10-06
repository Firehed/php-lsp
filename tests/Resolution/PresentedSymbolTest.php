<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\PresentedSymbol;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresentedSymbol::class)]
final class PresentedSymbolTest extends TestCase
{
    public function testKeepsTheSignatureAndDocumentation(): void
    {
        $presented = new PresentedSymbol('function double(int $value): int', 'Doubles the input.');

        self::assertSame('function double(int $value): int', $presented->signature, 'the signature is kept');
        self::assertSame('Doubles the input.', $presented->documentation, 'the documentation is kept');
    }
}
