<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

enum MemberAccessKind
{
    case Instance;  // $obj-> or $obj?->
    case Static;    // Class:: (includes self::, static::)
    case Parent;    // parent:: (both instance and static methods)
}
