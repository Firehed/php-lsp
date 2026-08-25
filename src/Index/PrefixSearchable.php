<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\NameKind;

/**
 * A source that can enumerate symbols whose short name begins with a prefix.
 *
 * Implemented by bounded, already-indexed sources — the autoload.files derived
 * index and the reflection-enumerated built-ins — where a prefix scan is a
 * filter over a list already in memory, not an unbounded walk.
 */
interface PrefixSearchable
{
    /**
     * @return list<Symbol>
     */
    public function searchByPrefix(string $prefix, NameKind $kind): array;
}
