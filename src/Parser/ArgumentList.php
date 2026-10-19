<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

/**
 * A call's own argument list, as file positions.
 */
final readonly class ArgumentList
{
    /**
     * @param int $opensAt The `(` that opens the list.
     * @param list<int> $separators The commas, in order, that separate its own
     *        arguments, a trailing comma included.
     */
    public function __construct(
        public int $opensAt,
        public array $separators,
    ) {
    }
}
