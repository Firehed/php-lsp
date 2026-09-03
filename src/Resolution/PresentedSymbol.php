<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

/**
 * The shape hover, signature-help, and completion-detail all consume. Fields
 * added here reach every surface at once, so a new user-facing attribute is one
 * edit rather than three.
 */
final readonly class PresentedSymbol
{
    public function __construct(
        public string $signature,
        public ?string $documentation,
    ) {
    }
}
