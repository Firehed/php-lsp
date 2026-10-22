<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\ResolvedVariable;

/**
 * What is in scope at a position.
 */
interface ScopeAtPositionInterface
{
    /**
     * The variables bound before the position in its scope, `$this` included
     * where it is bound.
     *
     * @return list<ResolvedVariable>
     */
    public function variablesAt(TextDocument $document, ByteOffset $offset): array;

    /**
     * The namespace and imports in effect at the position.
     */
    public function nameContextAt(TextDocument $document, ByteOffset $offset): NameContext;
}
