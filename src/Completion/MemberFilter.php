<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

enum MemberFilter
{
    case Instance;
    case Static;
    case Both;

    public function matches(bool $isStatic): bool
    {
        return match ($this) {
            self::Instance => !$isStatic,
            self::Static => $isStatic,
            self::Both => true,
        };
    }
}
