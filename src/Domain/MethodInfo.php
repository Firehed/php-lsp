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

    public function format(bool $showDefaults = false): string
    {
        $parts = [$this->visibility->format()];
        if ($this->isStatic) {
            $parts[] = 'static';
        }
        if ($this->isAbstract) {
            $parts[] = 'abstract';
        }
        if ($this->isFinal) {
            $parts[] = 'final';
        }
        $parts[] = 'function';

        $params = array_map(fn($p) => $p->format(showDefault: $showDefaults), $this->parameters);
        $parts[] = $this->name->name . '(' . implode(', ', $params) . ')';

        $sig = implode(' ', $parts);
        if ($this->returnType !== null) {
            $sig .= ': ' . $this->returnType;
        }
        return $sig;
    }
}
