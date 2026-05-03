<?php

declare(strict_types=1);

namespace Fixtures\Utility;

abstract class AbstractBase
{
    abstract public function doSomething(): void;
}

final class SealedClass
{
    public function doSomething(): void
    {
    }
}

readonly class ImmutableClass
{
    public function __construct(
        public string $value,
    ) {
    }
}
