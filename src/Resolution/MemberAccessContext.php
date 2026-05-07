<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * Context for member access completion.
 * Captures the type being accessed, visibility level, and access kind.
 */
final readonly class MemberAccessContext
{
    public function __construct(
        public Type $type,
        public Visibility $minVisibility,
        public MemberAccessKind $kind,
        public string $prefix,
    ) {
    }
}
