<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

final class DocblockParser
{
    /**
     * Extract the element type from a docblock's `@var`, `@return`, or
     * `@phpstan-*` array shape. Supports `T[]`, `?T[]`, `array<T>`,
     * `array<K, V>` (returns V), `list<T>`, `iterable<T>`, and
     * `iterable<K, V>` (returns V). Returns the raw type token; caller
     * resolves it against its own name context.
     */
    public static function arrayElementType(string $docblock): ?string
    {
        foreach (['@var', '@return', '@psalm-return', '@phpstan-return'] as $tag) {
            $type = self::extractTagType($docblock, $tag);
            if ($type === null) {
                continue;
            }
            $elem = self::elementFromShape($type);
            if ($elem !== null) {
                return $elem;
            }
        }
        return null;
    }

    private static function extractTagType(string $docblock, string $tag): ?string
    {
        $pattern = '/' . preg_quote($tag, '/') . '\s+([^\s]+(?:\s*<[^>]*>)?)/';
        if (preg_match($pattern, $docblock, $m) !== 1) {
            return null;
        }
        return trim($m[1]);
    }

    private static function elementFromShape(string $type): ?string
    {
        if (preg_match('/^\??([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\[\]$/', $type, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(?:array|list|iterable)<\s*(.+)\s*>$/', $type, $m) === 1) {
            $parts = self::splitTopLevel($m[1]);
            $last = trim($parts[count($parts) - 1]);
            if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)$/', $last) === 1) {
                return $last;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private static function splitTopLevel(string $params): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        for ($i = 0, $length = strlen($params); $i < $length; $i++) {
            $ch = $params[$i];
            if ($ch === '<') {
                $depth++;
            } elseif ($ch === '>') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $parts[] = $current;
        }
        return $parts;
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
