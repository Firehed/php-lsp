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

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Pending => 'Pending',
            self::Suspended => 'Suspended',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Suspended;
    }

    public function triggerDefEnumMethod(): string
    {
        return $this->label(); //hover:enum_method
    }

    public function triggerEnumCase(): Status
    {
        return self::Active; //hover:enum_case
    }
}
