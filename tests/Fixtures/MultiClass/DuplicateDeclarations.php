<?php

declare(strict_types=1);

namespace Fixtures\MultiClass;

// A name declared twice in one file: the unguarded declaration runs first, so it is
// the one PHP defines. The guarded twin differs in every discriminating detail
// (final-ness, members, return type) so a lookup reveals which declaration won.
final class Duplicated
{
    public function fromFirstDeclaration(): void
    {
    }
}

function duplicated(): string
{
    return '';
}

if (!class_exists(Duplicated::class)) {
    class Duplicated
    {
        public function fromSecondDeclaration(): void
        {
        }
    }
}

if (!function_exists(__NAMESPACE__ . '\duplicated')) {
    function duplicated(): int
    {
        return 0;
    }
}
