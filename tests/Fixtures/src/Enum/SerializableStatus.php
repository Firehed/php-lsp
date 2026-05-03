<?php

declare(strict_types=1);

namespace Fixtures\Enum;

enum SerializableStatus: string implements \JsonSerializable
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
