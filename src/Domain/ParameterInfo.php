<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a method or function parameter.
 */
final readonly class ParameterInfo implements ResolvedSymbol
{
    public function __construct(
        public string $name,
        public ?Type $type,
        public bool $hasDefault,
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

    public function getType(): ?Type
    {
        return $this->type;
    }

    /**
     * The parameter's typed name, e.g. `int $count` or `string ...$tail`. A
     * standalone method (not on any interface) so a caller composing a callable's
     * signature does not go through {@see ResolvedSymbol::format()}, which the
     * presenter owns.
     */
    public static function signature(self $parameter, bool $showDefault = false): string
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
        if ($showDefault && $parameter->hasDefault && !$parameter->isVariadic) {
            $str .= ' = ' . ($parameter->defaultValue ?? '...');
        }
        return $str;
    }

    public function format(bool $showDefault = false): string
    {
        return self::signature($this, $showDefault);
    }
}
