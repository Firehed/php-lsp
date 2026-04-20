<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

enum MemberFilter
{
    case Instance;
    case Static;
    case Both;
}
