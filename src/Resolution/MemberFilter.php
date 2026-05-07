<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

enum MemberFilter
{
    case Instance;
    case Static;
    case All;
}
