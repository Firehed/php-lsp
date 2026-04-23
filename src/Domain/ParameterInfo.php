<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Utility\TypeFormatter;
use ReflectionParameter;

/**
 * Metadata about a method or function parameter.
 */
final readonly class ParameterInfo implements Formattable
{
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $hasDefault,
        public bool $isVariadic,
        public bool $isPassedByReference,
    ) {
    }

    public static function fromReflection(ReflectionParameter $param): self
    {
        return new self(
            name: $param->getName(),
            type: $param->getType() !== null
                ? TypeFormatter::formatReflection($param->getType())
                : null,
            hasDefault: $param->isDefaultValueAvailable(),
            isVariadic: $param->isVariadic(),
            isPassedByReference: $param->isPassedByReference(),
        );
    }

    public function format(bool $showDefault = false): string
    {
        $str = '';
        if ($this->type !== null) {
            $str .= $this->type . ' ';
        }
        if ($this->isPassedByReference) {
            $str .= '&';
        }
        if ($this->isVariadic) {
            $str .= '...';
        }
        $str .= '$' . $this->name;
        if ($showDefault && $this->hasDefault && !$this->isVariadic) {
            $str .= ' = ...';
        }
        return $str;
    }
}
