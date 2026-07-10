<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * The result of classifying a cursor position: the kind of completion that
 * applies and the identifier prefix already typed at that position.
 */
final readonly class CompletionClassification
{
    public function __construct(
        public CompletionKind $kind,
        public string $prefix,
    ) {
    }
}
