<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a standalone function.
 */
final readonly class FunctionInfo implements Formattable
{
    /**
     * @param list<ParameterInfo> $parameters
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public ?string $returnType,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
    ) {
    }

    public function format(): string
    {
        $params = array_map(fn($p) => $p->format(), $this->parameters);
        $sig = 'function ' . $this->name . '(' . implode(', ', $params) . ')';
        if ($this->returnType !== null) {
            $sig .= ': ' . $this->returnType;
        }
        return $sig;
    }
}
