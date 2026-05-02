<?php

declare(strict_types=1);

namespace Fixtures\Traits;

class ConcreteService
{
    use SingletonTrait;

    public string $serviceName = 'concrete';

    public function getServiceName(): string
    {
        return $this->serviceName;
    }
}
