<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

trait SupportsOwnMethod
{
    public function supports(string $method): bool
    {
        return $method === static::method();
    }
}
