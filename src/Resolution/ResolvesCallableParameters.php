<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\Type;

/**
 * Provides ResolvedCallable implementations.
 *
 * Expects the using class to have a `$this->info` property with `parameters`
 * (array of ParameterInfo) and `returnType` (?Type) properties.
 */
trait ResolvesCallableParameters
{
    public function getParameters(): array
    {
        return $this->info->parameters;
    }

    public function getReturnType(): ?Type
    {
        return $this->info->returnType;
    }

    public function getParameterAtPosition(int $position): ?ParameterInfo
    {
        foreach ($this->info->parameters as $param) {
            if ($param->position === $position) {
                return $param;
            }
        }
        return null;
    }

    public function getParameterByName(string $name): ?ParameterInfo
    {
        foreach ($this->info->parameters as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }
        return null;
    }
}
