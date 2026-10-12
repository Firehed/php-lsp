<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a method or function parameter.
 */
final readonly class ParameterInfo implements ResolvedSymbolInterface
{
    public function __construct(
        public string $name,
        public ?TypeInterface $type,
        /** The default as PHP source; null when the parameter has none. */
        public ?string $defaultValue,
        public int $position,
        public bool $isVariadic,
        public bool $isPassedByReference,
    ) {
    }

    /**
     * A parameter has no persistent definition location; it is declared inline
     * in the function or method signature the caller already has.
     */
    public function getDefinitionLocation(): ?Location
    {
        return null;
    }

    public function getDocumentation(): ?string
    {
        return null;
    }

    public function getType(): ?TypeInterface
    {
        return $this->type;
    }

    /**
     * The parameter as declared, e.g. `int $count = 0` or `string ...$tail`. A
     * standalone method (not on any interface) so a caller composing a callable's
     * signature does not go through {@see ResolvedSymbolInterface::format()}, which the
     * presenter owns.
     */
    public static function signature(self $parameter): string
    {
        $str = '';
        if ($parameter->type !== null) {
            $str .= $parameter->type->format() . ' ';
        }
        if ($parameter->isPassedByReference) {
            $str .= '&';
        }
        if ($parameter->isVariadic) {
            $str .= '...';
        }
        $str .= '$' . $parameter->name;
        if ($parameter->defaultValue !== null && !$parameter->isVariadic) {
            $str .= ' = ' . $parameter->defaultValue;
        }
        return $str;
    }

    public function format(): string
    {
        return self::signature($this);
    }
}
