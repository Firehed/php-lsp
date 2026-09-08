<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
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
 * is parsed, so the choice of parser is confined to this file. The wrapper
 * converts the library's type AST into the project's {@see Type} model, so
 * consumers never see a `TypeNode`.
 */
final class DocblockParser
{
    /**
     * Identifiers that are not class-like names and therefore must not be
     * routed through the class-name resolver — native primitives, PHPStan/Psalm
     * pseudo-types, generic hints, and late-binding keywords.
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
     * `psalm-`/`phpstan-` spellings) — as domain {@see Type} values with every
     * class-like name fully qualified through `$resolveClassName`. `phpstan-`
     * overrides `psalm-` overrides the native tag.
     *
     * @param \Closure(string): string $resolveClassName Resolves an
     *        unqualified or relative class name (as it appears in the
     *        docblock) to a fully-qualified name without a leading `\`.
     * @return array{return?: Type, var?: Type, params?: array<string, Type>}
     */
    public static function extractResolvedTypedTags(string $docblock, \Closure $resolveClassName): array
    {
        $doc = self::parse($docblock);
        $tags = [];

        foreach (['@var', '@psalm-var', '@phpstan-var'] as $tagName) {
            foreach ($doc->getVarTagValues($tagName) as $node) {
                $type = self::convert($node->type, $resolveClassName);
                if ($type !== null) {
                    $tags['var'] = $type;
                }
            }
        }

        foreach (['@return', '@psalm-return', '@phpstan-return'] as $tagName) {
            foreach ($doc->getReturnTagValues($tagName) as $node) {
                $type = self::convert($node->type, $resolveClassName);
                if ($type !== null) {
                    $tags['return'] = $type;
                }
            }
        }

        $params = [];
        foreach (['@param', '@psalm-param', '@phpstan-param'] as $tagName) {
            foreach ($doc->getParamTagValues($tagName) as $node) {
                $type = self::convert($node->type, $resolveClassName);
                if ($type !== null) {
                    $params[ltrim($node->parameterName, '$')] = $type;
                }
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
     * first {@see PhpDocTagNode}.
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
     * @param \Closure(string): string $resolveClassName
     */
    private static function convert(TypeNode $node, \Closure $resolveClassName): ?Type
    {
        if ($node instanceof UnionTypeNode) {
            $members = self::convertAll($node->types, $resolveClassName);
            return $members === [] ? null : TypeFactory::union($members);
        }
        if ($node instanceof IntersectionTypeNode) {
            $members = self::convertAll($node->types, $resolveClassName);
            return $members === [] ? null : TypeFactory::intersection($members);
        }
        if ($node instanceof NullableTypeNode) {
            $inner = self::convert($node->type, $resolveClassName);
            return $inner === null ? null : TypeFactory::nullable($inner);
        }
        if ($node instanceof ArrayTypeNode) {
            $inner = self::convert($node->type, $resolveClassName);
            return TypeFactory::primitive('array', $inner !== null ? [$inner] : []);
        }
        if ($node instanceof GenericTypeNode) {
            $args = self::convertAll($node->genericTypes, $resolveClassName);
            $lastArg = $args === [] ? [] : [$args[count($args) - 1]];
            return self::identifierType($node->type->name, $lastArg, $resolveClassName);
        }
        if ($node instanceof IdentifierTypeNode) {
            return self::identifierType($node->name, [], $resolveClassName);
        }
        return null;
    }

    /**
     * @param array<TypeNode> $nodes
     * @param \Closure(string): string $resolveClassName
     * @return list<Type>
     */
    private static function convertAll(array $nodes, \Closure $resolveClassName): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $type = self::convert($node, $resolveClassName);
            if ($type !== null) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * @param list<Type> $typeArgs
     * @param \Closure(string): string $resolveClassName
     */
    private static function identifierType(string $name, array $typeArgs, \Closure $resolveClassName): Type
    {
        if ($name === 'list') {
            return TypeFactory::primitive('array', $typeArgs);
        }
        if (in_array($name, PrimitiveType::NAMES, true)) {
            return TypeFactory::primitive($name, $typeArgs);
        }
        if (array_key_exists($name, self::TYPE_KEYWORDS)) {
            return TypeFactory::className($name, $typeArgs);
        }
        if (str_starts_with($name, '\\')) {
            return TypeFactory::className(ltrim($name, '\\'), $typeArgs);
        }
        return TypeFactory::className($resolveClassName($name), $typeArgs);
    }
}
