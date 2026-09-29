<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\DeclaredSymbol;

interface DeclarationSourceInterface
{
    /**
     * Every class-like, function, and constant the document declares.
     *
     * @return list<DeclaredSymbol>
     */
    public function declarationsIn(TextDocument $document): array;
}
