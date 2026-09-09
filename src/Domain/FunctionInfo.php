<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use ReflectionFunction;

/**
 * Metadata about a standalone function.
 */
final readonly class FunctionInfo implements ResolvedCallable, SymbolInfo
{
    use HasCallableParameters;
    use HasSymbolLocation;

    /**
     * @param list<ParameterInfo> $parameters
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public ?Type $returnType,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
    ) {
    }

    /**
     * @param ?string $file the declaring file, which the node does not carry;
     *        supplied when the function was resolved from a known file rather than
     *        from the document already being read
     */
    public static function fromNode(Stmt\Function_ $node, ?string $file = null): self
    {
        $params = [];
        foreach ($node->params as $position => $param) {
            $docblockType = self::docblockTypeForParam($node, $param);
            $paramInfo = ParameterInfo::fromNode($param, $position, docblockType: $docblockType);
            if ($paramInfo !== null) {
                $params[] = $paramInfo;
            }
        }

        return new self(
            name: $node->name->toString(),
            parameters: $params,
            returnType: TypeFactory::merge(
                TypeFactory::fromNode($node->returnType),
                TypeFactory::fromDocblockNode($node, 'return'),
            ),
            docblock: $node->getDocComment()?->getText(),
            file: $file,
            line: $node->getStartLine(),
        );
    }

    private static function docblockTypeForParam(Stmt\Function_ $function, Node\Param $param): ?Type
    {
        if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
            return null;
        }
        return TypeFactory::fromDocblockNode($function, 'param:' . $param->var->name);
    }

    public static function fromReflection(ReflectionFunction $func): self
    {
        return new self(
            name: $func->getName(),
            parameters: array_map(
                ParameterInfo::fromReflection(...),
                $func->getParameters(),
            ),
            returnType: TypeFactory::fromReflection($func->getReturnType()),
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

    public function getReturnType(): ?Type
    {
        return $this->returnType;
    }

    public function getType(): ?Type
    {
        return $this->returnType;
    }

    public function symbolKind(): SymbolKind
    {
        return SymbolKind::Function_;
    }

    public function format(): string
    {
        $params = array_map(fn($p) => ParameterInfo::signature($p), $this->parameters);
        $sig = 'function ' . $this->name . '(' . implode(', ', $params) . ')';
        if ($this->returnType !== null) {
            $sig .= ': ' . $this->returnType->format();
        }
        return $sig;
    }
}
