<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\Type;

/**
 * A resolved class, interface, trait, or enum wrapping ClassInfo.
 */
final readonly class ResolvedClass implements ResolvedSymbol
{
    use ResolvesFromInfo;

    public function __construct(
        private ClassInfo $info,
    ) {
    }

    /**
     * Returns the class name as a Type. This allows class references to be
     * typed in the same way as other symbols.
     */
    public function getType(): Type
    {
        return $this->info->name;
    }
}
