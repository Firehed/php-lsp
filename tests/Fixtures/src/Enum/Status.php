<?php

declare(strict_types=1);

namespace Fixtures\Enum;

/**
 * User account status.
 */
enum Status
{
    case Active;
    case Inactive;
    case Pending;
    case Suspended;
}
