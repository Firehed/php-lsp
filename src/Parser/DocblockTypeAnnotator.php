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
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node as PhpDocParserNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser as PhpDocNodeTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
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
 * or `'param:name'`. {@see \Firehed\PhpLsp\Domain\TypeFactory::fromDocblockNode}
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

    /**
     * Resolves a single identifier to its fully qualified form, or leaves it as
     * a docblock primitive keyword. Public so the anonymous visitor used by
     * {@see resolveNames} can call it through a first-class callable without
     * needing access to private state.
     */
    public function resolveIdentifier(string $name): string
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

    /**
     * Walk the TypeNode tree through phpdoc-parser's own NodeTraverser so
     * descent is driven by the library's generic reflection over public
     * properties. Only `IdentifierTypeNode` occurrences are rewritten, and
     * shape-item key positions are excluded because they name shape keys, not
     * class-like symbols. A new `TypeNode` subclass added by the library gains
     * name resolution automatically, without a change here.
     */
    private function resolveNames(TypeNode $node): void
    {
        (new PhpDocNodeTraverser([$this->identifierResolvingVisitor()]))->traverse([$node]);
    }

    private function identifierResolvingVisitor(): AbstractNodeVisitor
    {
        $resolve = $this->resolveIdentifier(...);
        return new class ($resolve) extends AbstractNodeVisitor {
            /** @var callable(string): string */
            private $resolve;

            /**
             * @param callable(string): string $resolve
             */
            public function __construct(callable $resolve)
            {
                $this->resolve = $resolve;
            }

            public function enterNode(PhpDocParserNode $node): ?int
            {
                if ($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) {
                    // Shape-item `keyName` may itself be an IdentifierTypeNode
                    // but names a shape key, not a class-like symbol. Descend
                    // only into the value type so the key stays as written.
                    (new PhpDocNodeTraverser([$this]))->traverse([$node->valueType]);
                    return PhpDocNodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof IdentifierTypeNode) {
                    $node->name = ($this->resolve)($node->name);
                }
                return null;
            }
        };
    }
}
