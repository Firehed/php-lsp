<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Name as PhpParserName;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Reads the `@var`, `@return`, and `@param` tags (and their `psalm-`/`phpstan-`
 * spellings) of every `ClassMethod`, `Property`, `ClassConst`, and `Function_`
 * node, resolves class-name identifiers through the same {@see NameContext} the
 * {@see \PhpParser\NodeVisitor\NameResolver} populates, and stores the result
 * on the node as a `resolvedDocblockTypes` attribute keyed by tag.
 *
 * The stored value is a `map<string, TypeNode>` with keys `'return'`, `'var'`,
 * or `'param:name'`. {@see \Firehed\PhpLsp\Domain\TypeFactory::fromDocblockType}
 * turns each `TypeNode` into a `Domain\Type` at read time.
 */
final class DocblockTypeAnnotator extends NodeVisitorAbstract
{
    private const array RETURN_TAGS = ['@return', '@psalm-return', '@phpstan-return'];
    private const array VAR_TAGS = ['@var', '@psalm-var', '@phpstan-var'];
    private const array PARAM_TAGS = ['@param', '@psalm-param', '@phpstan-param'];

    private const array PRIMITIVE_KEYWORDS = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
        'mixed', 'never', 'null', 'object', 'self', 'static', 'string',
        'true', 'void', 'parent',
        // PHPDoc-specific type keywords
        'list', 'non-empty-list', 'non-empty-array', 'non-empty-string',
        'numeric', 'numeric-string', 'positive-int', 'negative-int',
        'class-string', 'trait-string', 'callable-string', 'literal-string',
        'scalar', 'this', '$this', 'key-of', 'value-of',
    ];

    private PhpDocParser $parser;

    private Lexer $lexer;

    public function __construct(private readonly NameContext $nameContext)
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new PhpDocParser($config, $typeParser, $constExprParser);
    }

    public function enterNode(Node $node): ?int
    {
        if (
            !$node instanceof ClassMethod
            && !$node instanceof Function_
            && !$node instanceof Property
            && !$node instanceof ClassConst
        ) {
            return null;
        }

        $docComment = $node->getDocComment();
        if ($docComment === null) {
            return null;
        }

        $phpDoc = $this->parseDocblock($docComment->getText());
        $tags = $this->readTags($phpDoc);
        if ($tags === []) {
            return null;
        }

        foreach ($tags as $type) {
            $this->resolveNames($type);
        }

        $node->setAttribute('resolvedDocblockTypes', $tags);

        return null;
    }

    private function parseDocblock(string $text): PhpDocNode
    {
        $tokens = new TokenIterator($this->lexer->tokenize($text));
        return $this->parser->parse($tokens);
    }

    /**
     * @return array<string, TypeNode>
     */
    private function readTags(PhpDocNode $phpDoc): array
    {
        $out = [];
        foreach (self::RETURN_TAGS as $tag) {
            foreach ($phpDoc->getReturnTagValues($tag) as $value) {
                $out['return'] ??= $value->type;
            }
        }
        foreach (self::VAR_TAGS as $tag) {
            foreach ($phpDoc->getVarTagValues($tag) as $value) {
                $out['var'] ??= $value->type;
            }
        }
        foreach (self::PARAM_TAGS as $tag) {
            foreach ($phpDoc->getParamTagValues($tag) as $value) {
                $key = 'param:' . ltrim($value->parameterName, '$');
                $out[$key] ??= $value->type;
            }
        }
        return $out;
    }

    private function resolveNames(TypeNode $node): void
    {
        if ($node instanceof IdentifierTypeNode) {
            $node->name = $this->resolveIdentifier($node->name);
            return;
        }
        if ($node instanceof ArrayTypeNode) {
            $this->resolveNames($node->type);
            return;
        }
        if ($node instanceof NullableTypeNode) {
            $this->resolveNames($node->type);
            return;
        }
        if ($node instanceof GenericTypeNode) {
            $this->resolveNames($node->type);
            foreach ($node->genericTypes as $inner) {
                $this->resolveNames($inner);
            }
            return;
        }
        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $inner) {
                $this->resolveNames($inner);
            }
            return;
        }
        if ($node instanceof CallableTypeNode) {
            $this->resolveNames($node->identifier);
            foreach ($node->parameters as $parameter) {
                $this->resolveNames($parameter->type);
            }
            $this->resolveNames($node->returnType);
            return;
        }
        if ($node instanceof ArrayShapeNode) {
            foreach ($node->items as $item) {
                $this->resolveNames($item->valueType);
            }
            return;
        }
        if ($node instanceof ObjectShapeNode) {
            foreach ($node->items as $item) {
                $this->resolveNames($item->valueType);
            }
            return;
        }
        if ($node instanceof OffsetAccessTypeNode) {
            $this->resolveNames($node->type);
            $this->resolveNames($node->offset);
            return;
        }
        if ($node instanceof ConditionalTypeNode) {
            $this->resolveNames($node->subjectType);
            $this->resolveNames($node->targetType);
            $this->resolveNames($node->if);
            $this->resolveNames($node->else);
            return;
        }
        if ($node instanceof ConditionalTypeForParameterNode) {
            $this->resolveNames($node->targetType);
            $this->resolveNames($node->if);
            $this->resolveNames($node->else);
        }
    }

    private function resolveIdentifier(string $name): string
    {
        // Docblock primitive keywords are conventionally lowercase in every codebase
        // this server sees, so a case-sensitive match is enough. A stray `Int` would
        // route through name resolution and produce a global-namespaced class name,
        // which resolves to nothing and is dropped by TypeFactory::fromDocblockType.
        if (in_array($name, self::PRIMITIVE_KEYWORDS, true)) {
            return $name;
        }
        return $this->nameContext->getResolvedClassName(new PhpParserName($name))->toString();
    }
}
