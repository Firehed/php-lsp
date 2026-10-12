<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;

/**
 * How a symbol reads to the user in hover, signature help, and completion.
 */
interface ResolvedSymbolPresenterInterface
{
    public function present(ResolvedSymbolInterface $symbol): PresentedSymbol;
}
