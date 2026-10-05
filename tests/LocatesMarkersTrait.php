<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

/**
 * Resolves fixture markers to positions with byte columns. Depends on nothing
 * in `src/`, so the end-to-end tier can use it.
 */
trait LocatesMarkersTrait
{
    /**
     * Resolves a fixture cursor marker to the position immediately before it.
     *
     * Markers use the pattern SLASH*|marker_name*SLASH (where SLASH is /).
     * This is the position math alone, with no document opened, so tests that
     * drive the server over the wire can address a marker too.
     *
     * @param string $cursorName The marker name (without delimiters)
     * @return array{line: int, character: int}
     */
    private function locateCursor(string $content, string $cursorName): array
    {
        ['line' => $line, 'before' => $before] = $this->splitAtCursor($content, $cursorName);

        return [
            'line' => $line,
            'character' => strlen($before),
        ];
    }

    /**
     * Resolves an end-of-line `//hover:name` marker to a position on a symbol:
     * the rightmost member access, function call, constant, or named argument
     * on the marked line, else its last variable.
     *
     * @return array{line: int, character: int}
     */
    private function locateHoverMarker(string $content, string $markerName): array
    {
        $marker = "//hover:$markerName";
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            $markerPos = strpos($line, $marker);
            if ($markerPos === false) {
                continue;
            }

            // Collect all symbol positions, return the rightmost one
            $candidates = [];

            // Member access: ->method, ?->method, ::method, ::$property
            $symbolMatch = [];
            preg_match_all('/(?:->|\?->|::)\$?([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $symbolMatch, PREG_OFFSET_CAPTURE);
            foreach ($symbolMatch[1] as $match) {
                $candidates[] = $match[1];
            }

            // Function calls: func(
            $funcMatch = [];
            preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $funcMatch, PREG_OFFSET_CAPTURE);
            foreach ($funcMatch[1] as $match) {
                $candidates[] = $match[1];
            }

            // Constants: bare identifiers not preceded by ->, ?->, ::, or $
            $constMatch = [];
            $constPattern = '/(?<!->|::|\\?->|\$)\b([A-Z_][A-Z0-9_]*)\b(?!\s*\()/';
            preg_match_all($constPattern, $line, $constMatch, PREG_OFFSET_CAPTURE);
            foreach ($constMatch[1] as $match) {
                $candidates[] = $match[1];
            }

            // Named arguments: identifier: (but not ::)
            $beforeMarker = substr($line, 0, $markerPos);
            $colonPos = strrpos($beforeMarker, ':');
            if ($colonPos !== false && ($colonPos === 0 || $beforeMarker[$colonPos - 1] !== ':')) {
                $identEnd = $colonPos;
                while ($identEnd > 0 && ctype_space($beforeMarker[$identEnd - 1])) {
                    $identEnd--;
                }
                $identStart = $identEnd;
                while ($identStart > 0) {
                    $char = $beforeMarker[$identStart - 1];
                    if (!ctype_alnum($char) && $char !== '_') {
                        break;
                    }
                    $identStart--;
                }
                if ($identStart < $identEnd) {
                    $candidates[] = $identStart;
                }
            }

            if ($candidates !== []) {
                return [
                    'line' => $lineNum,
                    'character' => max($candidates),
                ];
            }

            // Check for standalone variables
            $varMatch = [];
            preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $varMatch, PREG_OFFSET_CAPTURE);
            assert(count($varMatch[0]) > 0, "No symbol found on line with marker '$markerName'");

            $lastMatch = end($varMatch[0]);
            return [
                'line' => $lineNum,
                'character' => $lastMatch[1],
            ];
        }

        throw new \RuntimeException("Hover marker '$markerName' not found");
    }

    /**
     * Resolves an end-of-line `//jtd:name var` marker to a position on the last
     * `$var` on the marked line. A cursor marker cannot sit inside a variable
     * name without breaking the parse, so the marker names the variable instead.
     *
     * @return array{line: int, character: int}
     */
    private function locateVariableMarker(string $content, string $markerName): array
    {
        $marker = "//jtd:{$markerName} ";
        foreach (explode("\n", $content) as $lineNum => $line) {
            $markerPos = strpos($line, $marker);
            if ($markerPos === false) {
                continue;
            }
            $varName = trim(substr($line, $markerPos + strlen($marker)));
            $lastDollar = strrpos(substr($line, 0, $markerPos), '$' . $varName);
            assert($lastDollar !== false, "Fixture line for {$markerName} must contain \${$varName}");

            return [
                'line' => $lineNum,
                'character' => $lastDollar + 1,
            ];
        }

        throw new \RuntimeException("Marker //jtd:{$markerName} not found");
    }

    /**
     * The line the cursor marker sits on and the text preceding it on that line,
     * shared by the byte- and wire-column marker resolvers.
     *
     * @param string $cursorName The marker name (without delimiters)
     * @return array{line: int, before: string}
     */
    private function splitAtCursor(string $content, string $cursorName): array
    {
        $marker = "/*|{$cursorName}*/";
        $pos = strpos($content, $marker);
        assert($pos !== false, "Cursor marker not found: $cursorName");

        $beforeMarker = substr($content, 0, $pos);
        $lines = explode("\n", $beforeMarker);
        $line = count($lines) - 1;

        return [
            'line' => $line,
            'before' => $lines[$line],
        ];
    }
}
