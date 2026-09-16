<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a free-standing (namespace-level) constant.
 *
 * A free constant carries its own fully-qualified name and no declaring class;
 * the class-owned sibling is {@see ClasslikeConstantInfo}. The split lets the
 * member-resolver walk hand out only the class-owned variant, so the type
 * system enforces the invariant a nullable declaring class once stood in for.
 *
 * Visibility and finality do not apply here — a `const` or `define()` at
 * namespace level has neither.
 */
final readonly class ConstantInfo implements ResolvedSymbolInterface, SymbolInfoInterface
{
    use HasSymbolLocationTrait;

    public function __construct(
        public ConstantName $name,
        public ?TypeInterface $type,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
    ) {
    }

    public function format(): string
    {
        $parts = ['const', $this->name->qualifiedName->shortName];
        if ($this->type !== null) {
            array_splice($parts, 1, 0, [$this->type->format()]);
        }
        return implode(' ', $parts);
    }

    public function getType(): ?TypeInterface
    {
        return $this->type;
    }

    public function symbolKind(): SymbolKind
    {
        return SymbolKind::Constant;
    }
}
