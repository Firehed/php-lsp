<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class, interface, trait, or enum.
 */
final readonly class ClassInfo
{
    /**
     * @param list<ClassName> $interfaces Implemented interfaces
     * @param list<ClassName> $traits Used traits
     * @param array<string, MethodInfo> $methods Keyed by method name
     * @param array<string, PropertyInfo> $properties Keyed by property name
     * @param array<string, ConstantInfo> $constants Keyed by constant name
     * @param array<string, EnumCaseInfo> $enumCases Keyed by case name
     */
    public function __construct(
        public ClassName $name,
        public ClassKind $kind,
        public bool $isAbstract,
        public bool $isFinal,
        public bool $isReadonly,
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
    ) {
    }
}
