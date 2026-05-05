<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Utility\TypeFactory;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
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
        public ?string $defaultValue,
        public int $position,
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
        int $position,
        ?string $selfContext = null,
        ?string $parentContext = null,
    ): ?self {
        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        $defaultValue = null;
        if ($param->default !== null) {
            $printer = new PrettyPrinter();
            $defaultValue = $printer->prettyPrintExpr($param->default);
        }

        return new self(
            name: $param->var->name,
            type: TypeFactory::fromNode($param->type, $selfContext, $parentContext),
            hasDefault: $param->default !== null,
            defaultValue: $defaultValue,
            position: $position,
            isVariadic: $param->variadic,
            isPassedByReference: $param->byRef,
        );
    }

    public static function fromReflection(ReflectionParameter $param): self
    {
        $defaultValue = null;
        if ($param->isDefaultValueAvailable()) {
            $defaultValue = self::formatReflectionDefault($param->getDefaultValue());
        }

        return new self(
            name: $param->getName(),
            type: TypeFactory::fromReflection($param->getType()),
            hasDefault: $param->isDefaultValueAvailable(),
            defaultValue: $defaultValue,
            position: $param->getPosition(),
            isVariadic: $param->isVariadic(),
            isPassedByReference: $param->isPassedByReference(),
        );
    }

    private static function formatReflectionDefault(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === []) {
            return '[]';
        }
        return var_export($value, true);
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
            $str .= ' = ' . ($this->defaultValue ?? '...');
        }
        return $str;
    }
}
