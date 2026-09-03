<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\DocblockParser;
use Firehed\PhpLsp\Domain\ResolvedSymbol;

/**
 * The one place `ResolvedSymbol::format()` and `ResolvedSymbol::getDocumentation()`
 * are composed for a user-facing surface (hover, signature-help, completion-detail).
 * Tags are stripped so every surface shows the description rather than the raw
 * docblock. `getDocumentation()` itself still returns the raw text, which
 * {@see ExpressionResolver} reads for `foreach` element-type inference.
 */
final class ResolvedSymbolPresenter
{
    public static function present(ResolvedSymbol $symbol): PresentedSymbol
    {
        $docblock = $symbol->getDocumentation();
        $description = $docblock === null ? '' : DocblockParser::extractDescription($docblock);
        return new PresentedSymbol(
            signature: $symbol->format(),
            documentation: $description === '' ? null : $description,
        );
    }
}
