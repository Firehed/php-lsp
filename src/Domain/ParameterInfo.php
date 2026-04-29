<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Utility\TypeFactory;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use ReflectionParameter;

/**
 * Metadata about a method or function parameter.
 */
final readonly class ParameterInfo implements Formattable
{
    public function __construct(
        public string $name,
        public ?Type $type,
        public bool $hasDefault,
        public bool $isVariadic,
        public bool $isPassedByReference,
    ) {
    }

    /**
     * @param class-string|null $selfContext
     * @param class-string|null $parentContext
     */
    public static function fromNode(
        Param $param,
        ?string $selfContext = null,
        ?string $parentContext = null,
    ): ?self {
        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        return new self(
            name: $param->var->name,
            type: TypeFactory::fromNode($param->type, $selfContext, $parentContext),
            hasDefault: $param->default !== null,
            isVariadic: $param->variadic,
            isPassedByReference: $param->byRef,
        );
    }

    public static function fromReflection(ReflectionParameter $param): self
    {
        return new self(
            name: $param->getName(),
            type: TypeFactory::fromReflection($param->getType()),
            hasDefault: $param->isDefaultValueAvailable(),
            isVariadic: $param->isVariadic(),
            isPassedByReference: $param->isPassedByReference(),
        );
    }

    public function format(bool $showDefault = false): string
    {
        $str = '';
        if ($this->type !== null) {
            $str .= $this->type->format() . ' ';
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
