<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

enum VisibilityFilter
{
    case All;
    case PublicOnly;
    case PublicProtected;
}
