<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Utility\TypeFormatter;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;

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

    public static function fromNode(Param $param): ?self
    {
        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        return new self(
            name: $param->var->name,
            type: TypeFormatter::formatNode($param->type),
            hasDefault: $param->default !== null,
            isVariadic: $param->variadic,
            isPassedByReference: $param->byRef,
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
