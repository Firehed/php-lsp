<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a standalone function.
 */
final readonly class FunctionInfo implements ResolvedCallableInterface, SymbolInfoInterface
{
    use HasCallableParametersTrait;
    use HasSymbolLocationTrait;

    /**
     * @param list<ParameterInfo> $parameters
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public ?Type $returnType,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
    ) {
    }

    public function getReturnType(): ?Type
    {
        return $this->returnType;
    }

    public function getType(): ?Type
    {
        return $this->returnType;
    }

    public function symbolKind(): SymbolKind
    {
        return SymbolKind::Function_;
    }

    public function format(): string
    {
        $params = array_map(fn($p) => ParameterInfo::signature($p), $this->parameters);
        $sig = 'function ' . $this->name . '(' . implode(', ', $params) . ')';
        if ($this->returnType !== null) {
            $sig .= ': ' . $this->returnType->format();
        }
        return $sig;
    }
}
