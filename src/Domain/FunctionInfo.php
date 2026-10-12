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
        public FunctionName $name,
        public array $parameters,
        public ?TypeInterface $returnType,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
    ) {
    }

    public function getReturnType(): ?TypeInterface
    {
        return $this->returnType;
    }

    public function getType(): ?TypeInterface
    {
        return $this->returnType;
    }

    public function symbolKind(): SymbolKind
    {
        return SymbolKind::Function_;
    }

    public function format(): string
    {
        $sig = 'function ' . $this->name->qualifiedName->shortName . $this->formatParameterList();
        if ($this->returnType !== null) {
            $sig .= ': ' . $this->returnType->format();
        }
        return $sig;
    }
}
