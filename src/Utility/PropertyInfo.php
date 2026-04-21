<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

final class PropertyInfo
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type,
        public readonly ?string $docComment,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly bool $isStatic,
        public readonly bool $isReadonly,
        public readonly bool $isPublic,
        public readonly bool $isProtected,
        public readonly bool $isPrivate,
    ) {
    }
}
