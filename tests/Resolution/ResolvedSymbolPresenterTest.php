<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Resolution\PresentedSymbol;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresentedSymbol::class)]
#[CoversClass(ResolvedSymbolPresenter::class)]
final class ResolvedSymbolPresenterTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function documentationCases(): iterable
    {
        yield 'description before tags' => [
            "/**\n * Doubles the input.\n *\n * @param int \$value\n */",
            'Doubles the input.',
        ];
        yield 'tags only' => ['/** @return int */', null];
        yield 'no docblock' => [null, null];
    }

    #[DataProvider('documentationCases')]
    public function testDocumentationIsTheDescription(?string $docblock, ?string $expected): void
    {
        $symbol = self::createStub(ResolvedSymbolInterface::class);
        $symbol->method('getDocumentation')->willReturn($docblock);

        self::assertSame(
            $expected,
            ResolvedSymbolPresenter::present($symbol)->documentation,
            'every surface shows the description without tags, or nothing',
        );
    }

    public function testSignatureIsTheFormattedSymbol(): void
    {
        $symbol = self::createStub(ResolvedSymbolInterface::class);
        $symbol->method('format')->willReturn('function double(int $value): int');

        self::assertSame(
            'function double(int $value): int',
            ResolvedSymbolPresenter::present($symbol)->signature,
            'the signature is the symbol as it formats itself',
        );
    }
}
