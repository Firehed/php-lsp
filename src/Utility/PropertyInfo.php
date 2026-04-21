<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use PhpParser\Comment\Doc;
use PhpParser\Node;

final class PropertyInfo
{
    public function __construct(
        public readonly string $name,
        public readonly ?Node $type,
        public readonly ?Doc $docComment,
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
