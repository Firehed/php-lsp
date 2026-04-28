<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Utility\TypeFormatter;
use PhpParser\Node\Stmt;
use ReflectionFunction;

/**
 * Metadata about a standalone function.
 */
final readonly class FunctionInfo implements Formattable
{
    /**
     * @param list<ParameterInfo> $parameters
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public ?string $returnType,
        public ?Type $returnTypeInfo,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
    ) {
    }

    public static function fromNode(Stmt\Function_ $node): self
    {
        $params = array_filter(
            array_map(ParameterInfo::fromNode(...), $node->params),
            fn($p) => $p !== null,
        );

        return new self(
            name: $node->name->toString(),
            parameters: array_values($params),
            returnType: $node->returnType !== null
                ? TypeFormatter::formatNode($node->returnType)
                : null,
            returnTypeInfo: null,
            docblock: $node->getDocComment()?->getText(),
            file: null,
            line: $node->getStartLine(),
        );
    }

    public static function fromReflection(ReflectionFunction $func): self
    {
        return new self(
            name: $func->getName(),
            parameters: array_map(
                ParameterInfo::fromReflection(...),
                $func->getParameters(),
            ),
            returnType: $func->getReturnType() !== null
                ? TypeFormatter::formatReflection($func->getReturnType())
                : null,
            returnTypeInfo: null,
            docblock: $func->getDocComment() !== false
                ? $func->getDocComment()
                : null,
            file: $func->getFileName() !== false
                ? $func->getFileName()
                : null,
            line: $func->getStartLine() !== false
                ? $func->getStartLine()
                : null,
        );
    }

    public function format(): string
    {
        $params = array_map(fn($p) => $p->format(), $this->parameters);
        $sig = 'function ' . $this->name . '(' . implode(', ', $params) . ')';
        if ($this->returnType !== null) {
            $sig .= ': ' . $this->returnType;
        }
        return $sig;
    }
}
