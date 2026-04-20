<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

enum AccessContext
{
    case SameClass;
    case Subclass;
    case External;
}
