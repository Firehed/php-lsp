<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class method.
 */
final readonly class MethodInfo
{
    /**
     * @param list<ParameterInfo> $parameters
     */
    public function __construct(
        public MethodName $name,
        public Visibility $visibility,
        public bool $isStatic,
        public bool $isAbstract,
        public bool $isFinal,
        public array $parameters,
        public ?string $returnType,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public ClassName $declaringClass,
    ) {
    }
}
