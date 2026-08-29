<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The ResolvedCallable parameter lookups, shared across every *Info that
 * carries a `list<ParameterInfo> $parameters` field.
 */
trait HasCallableParameters
{
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getParameterAtPosition(int $position): ?ParameterInfo
    {
        foreach ($this->parameters as $param) {
            if ($param->position === $position) {
                return $param;
            }
        }
        return null;
    }

    public function getParameterByName(string $name): ?ParameterInfo
    {
        foreach ($this->parameters as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }
        return null;
    }
}
