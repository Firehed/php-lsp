<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Thin wrapper over `phpstan/phpdoc-parser`. This is the one place a docblock
 * is parsed, so the choice of parser is confined to this file.
 */
final class DocblockParser
{
    /**
     * Every non-class-like identifier that may appear as an
     * {@see IdentifierTypeNode} in a docblock type, so the resolver skips
     * them. Includes native primitives, PHPStan/Psalm pseudo-types, generic
     * hints, and late-binding keywords.
     *
     * @var array<string, true>
     */
    private const array TYPE_KEYWORDS = [
        'array' => true, 'bool' => true, 'callable' => true, 'false' => true,
        'float' => true, 'int' => true, 'iterable' => true, 'list' => true,
        'mixed' => true, 'never' => true, 'null' => true, 'object' => true,
        'parent' => true, 'resource' => true, 'scalar' => true, 'self' => true,
        'static' => true, 'string' => true, 'true' => true, 'void' => true,
        'array-key' => true, 'class-string' => true, 'callable-string' => true,
        'literal-string' => true, 'non-empty-string' => true,
        'non-empty-array' => true, 'non-empty-list' => true,
        'numeric-string' => true, 'positive-int' => true, 'negative-int' => true,
        'non-negative-int' => true, 'non-positive-int' => true, 'numeric' => true,
        'key-of' => true, 'value-of' => true, 'this' => true, '$this' => true,
    ];

    /**
     * Extract typed docblock tags — `@var`, `@return`, `@param` (native +
     * `psalm-`/`phpstan-` spellings) — with every class-like name fully
     * qualified through `$resolveClassName`. `phpstan-` overrides `psalm-`
     * overrides the native tag.
     *
     * @param \Closure(string): string $resolveClassName Resolves an
     *        unqualified or relative class name (as it appears in the
     *        docblock) to a fully-qualified name without a leading `\`.
     * @return array{return?: string, var?: string, params?: array<string, string>}
     */
    public static function extractResolvedTypedTags(string $docblock, \Closure $resolveClassName): array
    {
        $doc = self::parse($docblock);
        $tags = [];

        foreach (['@var', '@psalm-var', '@phpstan-var'] as $tagName) {
            foreach ($doc->getVarTagValues($tagName) as $node) {
                $tags['var'] = self::renderResolved($node->type, $resolveClassName);
            }
        }

        foreach (['@return', '@psalm-return', '@phpstan-return'] as $tagName) {
            foreach ($doc->getReturnTagValues($tagName) as $node) {
                $tags['return'] = self::renderResolved($node->type, $resolveClassName);
            }
        }

        $params = [];
        foreach (['@param', '@psalm-param', '@phpstan-param'] as $tagName) {
            foreach ($doc->getParamTagValues($tagName) as $node) {
                $params[ltrim($node->parameterName, '$')] = self::renderResolved(
                    $node->type,
                    $resolveClassName,
                );
            }
        }
        if ($params !== []) {
            $tags['params'] = $params;
        }

        return $tags;
    }

    /**
     * Extract the prose description from a docblock, stopping at the first
     * `@tag`. `PhpDocNode->children` is text nodes then tag nodes in source
     * order, so the description is every {@see PhpDocTextNode} before the
     * first {@see \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode}.
     */
    public static function extractDescription(string $docblock): string
    {
        $lines = [];
        foreach (self::parse($docblock)->children as $child) {
            if (!$child instanceof PhpDocTextNode) {
                break;
            }
            $text = trim($child->text);
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        return implode("\n", $lines);
    }

    private static function parse(string $docblock): PhpDocNode
    {
        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        $tokens = new TokenIterator((new Lexer($config))->tokenize($docblock));
        return $phpDocParser->parse($tokens);
    }

    /**
     * Walk the type tree, replace every class-like {@see IdentifierTypeNode}
     * with its fully-qualified form (either stripping a leading `\` or
     * routing through `$resolveClassName`), and print the result.
     *
     * @param \Closure(string): string $resolveClassName
     */
    private static function renderResolved(TypeNode $type, \Closure $resolveClassName): string
    {
        $visitor = new class ($resolveClassName) extends AbstractNodeVisitor {
            /**
             * @param \Closure(string): string $resolveClassName
             */
            public function __construct(private readonly \Closure $resolveClassName)
            {
            }

            public function enterNode(Node $node)
            {
                if (!$node instanceof IdentifierTypeNode) {
                    return null;
                }
                if (array_key_exists($node->name, DocblockParser::keywords())) {
                    return null;
                }
                if (str_starts_with($node->name, '\\')) {
                    $node->name = ltrim($node->name, '\\');
                    return null;
                }
                $node->name = ($this->resolveClassName)($node->name);
                return null;
            }
        };
        (new NodeTraverser([$visitor]))->traverse([$type]);
        $rendered = (string) $type;
        if ($type instanceof UnionTypeNode || $type instanceof IntersectionTypeNode) {
            // UnionTypeNode/IntersectionTypeNode always wrap themselves in
            // parentheses; drop them at the top level so a plain `A|B` reads as
            // itself and a nested (A&B)|C keeps its inner grouping.
            $rendered = substr($rendered, 1, -1);
        }
        return $rendered;
    }

    /**
     * The private keyword set, published so the anonymous visitor inside
     * {@see renderResolved()} can consult it.
     *
     * @return array<string, true>
     */
    public static function keywords(): array
    {
        return self::TYPE_KEYWORDS;
    }
}
