<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class Address
{
    public function __construct(
        private readonly string $street,
        private readonly string $city,
    ) {
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getCity(): string
    {
        return $this->city;
    }
}
