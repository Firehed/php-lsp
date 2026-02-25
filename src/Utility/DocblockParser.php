<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

final class DocblockParser
{
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
