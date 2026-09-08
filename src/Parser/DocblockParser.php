<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

final class DocblockParser
{
    private static function extractTagType(string $docblock, string $tag): ?string
    {
        $pattern = '/' . preg_quote($tag, '/') . '\s+([^\n@]+)/';
        if (preg_match($pattern, $docblock, $m) !== 1) {
            return null;
        }
        return self::firstTypeToken(trim($m[1]));
    }

    /**
     * Take the first well-formed type from a tag tail. Whitespace at depth 0
     * ends the token, so `list<User> element` stops at `list<User>` and
     * `array<int, string>` stays intact (the space is inside `<>`).
     */
    private static function firstTypeToken(string $tail): string
    {
        $depth = 0;
        $end = strlen($tail);
        for ($i = 0; $i < $end; $i++) {
            $ch = $tail[$i];
            if ($ch === '<' || $ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === '>' || $ch === '}') {
                $depth--;
                continue;
            }
            if ($depth === 0 && ($ch === ' ' || $ch === "\t")) {
                return substr($tail, 0, $i);
            }
        }
        return $tail;
    }

    /**
     * Every non-class-like identifier that may appear in a docblock type
     * string, so the resolver skips them. Includes native primitives,
     * PHPStan/Psalm pseudo-types, generic hints, and late-binding keywords.
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
        $tags = [];
        foreach (['@var', '@psalm-var', '@phpstan-var'] as $tag) {
            $raw = self::extractTagType($docblock, $tag);
            if ($raw !== null) {
                $tags['var'] = self::resolveNamesInType($raw, $resolveClassName);
            }
        }
        foreach (['@return', '@psalm-return', '@phpstan-return'] as $tag) {
            $raw = self::extractTagType($docblock, $tag);
            if ($raw !== null) {
                $tags['return'] = self::resolveNamesInType($raw, $resolveClassName);
            }
        }
        $params = [];
        foreach (['@param', '@psalm-param', '@phpstan-param'] as $tag) {
            foreach (self::extractParamTags($docblock, $tag) as $name => $raw) {
                $params[$name] = self::resolveNamesInType($raw, $resolveClassName);
            }
        }
        if ($params !== []) {
            $tags['params'] = $params;
        }
        return $tags;
    }

    /**
     * @param \Closure(string): string $resolveClassName
     */
    private static function resolveNamesInType(string $type, \Closure $resolveClassName): string
    {
        return preg_replace_callback(
            '/[A-Za-z_\\\\$][A-Za-z0-9_\\\\-]*/',
            static function (array $m) use ($resolveClassName): string {
                $token = $m[0];
                if (array_key_exists($token, self::TYPE_KEYWORDS)) {
                    return $token;
                }
                if (str_starts_with($token, '\\')) {
                    return ltrim($token, '\\');
                }
                return $resolveClassName($token);
            },
            $type,
        ) ?? $type;
    }

    /**
     * @return iterable<string, string>
     */
    private static function extractParamTags(string $docblock, string $tag): iterable
    {
        $pattern = '/' . preg_quote($tag, '/') . '\s+([^\n]+)/';
        if (preg_match_all($pattern, $docblock, $matches, PREG_SET_ORDER) === false) {
            return;
        }
        foreach ($matches as $match) {
            $tail = trim($match[1]);
            $type = self::firstTypeToken($tail);
            $rest = ltrim(substr($tail, strlen($type)));
            if (preg_match('/^&?(?:\.\.\.)?\$(\w+)/', $rest, $nameMatch) === 1) {
                yield $nameMatch[1] => $type;
            }
        }
    }

    /**
     * Extract the prose description from a docblock, stopping at @tags.
     */
    public static function extractDescription(string $docblock): string
    {
        $lines = explode("\n", $docblock);
        $cleaned = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*\s*/', '', $line) ?? '';
            $line = preg_replace('/^\*\/\s*$/', '', $line) ?? '';
            $line = preg_replace('/^\*\s?/', '', $line) ?? '';

            // Stop at @param, @return, etc.
            if (str_starts_with($line, '@')) {
                break;
            }

            if ($line !== '') {
                $cleaned[] = $line;
            }
        }

        return implode("\n", $cleaned);
    }
}
