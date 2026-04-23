<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class method.
 */
final readonly class MethodInfo implements Formattable
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

    public function format(): string
    {
        $params = array_map(fn($p) => $p->format(), $this->parameters);
        $sig = $this->name->name . '(' . implode(', ', $params) . ')';
        if ($this->returnType !== null) {
            $sig .= ': ' . $this->returnType;
        }
        return $sig;
    }
}
