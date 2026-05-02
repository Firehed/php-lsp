<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

use Fixtures\Traits\ConcreteService;

class TraitStaticReturn
{
    public function callTraitStaticMethod(): ConcreteService
    {
        return ConcreteService::instance();
    }

    public function chainAfterTraitStaticMethod(): string
    {
        return ConcreteService::instance()->getServiceName();
    }

    public function callNullableTraitStaticMethod(): ?ConcreteService
    {
        return ConcreteService::tryInstance();
    }
}
