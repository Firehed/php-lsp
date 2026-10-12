<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The ResolvedCallableInterface parameter lookups, shared across every *Info that
 * carries a `list<ParameterInfo> $parameters` field.
 */
trait HasCallableParametersTrait
{
    public function getParameters(): array
    {
        return $this->parameters;
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

    private function formatParameterList(): string
    {
        return '(' . implode(', ', array_map(fn (ParameterInfo $p) => $p->format(), $this->parameters)) . ')';
    }
}
