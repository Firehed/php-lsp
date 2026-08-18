<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

enum MemberFilter
{
    case Instance;
    case Static;
    case All;
}
