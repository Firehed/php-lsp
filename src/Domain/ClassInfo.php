<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class, interface, trait, or enum.
 */
final readonly class ClassInfo implements ResolvedSymbol, SymbolInfo
{
    use HasSymbolLocation;

    /**
     * @param list<ClassName> $interfaces Implemented interfaces
     * @param list<ClassName> $traits Used traits
     * @param array<string, MethodInfo> $methods Keyed by method name
     * @param array<string, PropertyInfo> $properties Keyed by property name
     * @param array<string, ConstantInfo> $constants Keyed by constant name
     * @param array<string, EnumCaseInfo> $enumCases Keyed by case name
     * @param array<string, list<string>> $traitExclusions Methods excluded from
     *     a used trait by an `A::method insteadof B` clause, keyed by the
     *     losing trait's FQN.
     * @param list<TraitAlias> $traitAliases `as` clauses declared in this
     *     class's `use TraitX { ... }` block.
     */
    public function __construct(
        public ClassName $name,
        public ClassKind $kind,
        public bool $isAbstract,
        public bool $isFinal,
        public bool $isReadonly,
        public bool $isAttribute,
        public ?ClassName $parent,
        public array $interfaces,
        public array $traits,
        public array $methods,
        public array $properties,
        public array $constants,
        public array $enumCases,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public array $traitExclusions = [],
        public array $traitAliases = [],
    ) {
    }

    /**
     * A resolved class's value type is the class itself.
     */
    public function getType(): ClassName
    {
        return $this->name;
    }

    public function isClass(): bool
    {
        return $this->kind === ClassKind::Class_;
    }

    public function isInterface(): bool
    {
        return $this->kind === ClassKind::Interface_;
    }

    public function isTrait(): bool
    {
        return $this->kind === ClassKind::Trait_;
    }

    public function format(): string
    {
        $parts = [];
        if ($this->isFinal) {
            $parts[] = 'final';
        }
        if ($this->isAbstract) {
            $parts[] = 'abstract';
        }
        if ($this->isReadonly) {
            $parts[] = 'readonly';
        }

        $parts[] = match ($this->kind) {
            ClassKind::Interface_ => 'interface',
            ClassKind::Trait_ => 'trait',
            ClassKind::Enum_ => 'enum',
            default => 'class',
        };
        $parts[] = $this->name->shortName();

        $sig = implode(' ', $parts);

        if ($this->kind === ClassKind::Class_ && $this->parent !== null) {
            $sig .= ' extends ' . $this->parent->shortName();
        }
        $writtenInterfaces = $this->kind === ClassKind::Enum_
            ? array_values(array_filter(
                $this->interfaces,
                fn($n) => $n->fqn !== \UnitEnum::class && $n->fqn !== \BackedEnum::class,
            ))
            : $this->interfaces;
        if ($this->kind === ClassKind::Interface_ && $writtenInterfaces !== []) {
            $sig .= ' extends ' . implode(', ', array_map(fn($n) => $n->shortName(), $writtenInterfaces));
        } elseif ($writtenInterfaces !== []) {
            $sig .= ' implements ' . implode(', ', array_map(fn($n) => $n->shortName(), $writtenInterfaces));
        }

        return $sig;
    }
}
