<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Type-safe wrapper for method names.
 */
final readonly class MethodName
{
    public function __construct(
        public string $name,
    ) {
    }

    public function equals(self $other): bool
    {
        return MemberKind::Method->normalize($this->name) === MemberKind::Method->normalize($other->name);
    }
}
