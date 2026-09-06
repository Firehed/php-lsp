<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node\Stmt;

/**
 * Text-based fallback for code resolution when AST-based detection fails.
 *
 * Handles cases where PHP-Parser drops incomplete code (e.g., `if ($this->|)`).
 * Uses regex patterns to detect the enclosing class-like and parameter types.
 *
 * @internal
 */
final class TextFallbackHelper
{
    /**
     * Find enclosing class name by scanning document text.
     *
     * @return class-string|null
     */
    public function findEnclosingClass(TextDocument $document, int $line): ?string
    {
        return $this->findEnclosingClassFromContent($document->getContent(), $line);
    }

    /**
     * Find enclosing class name by scanning content text.
     *
     * @return class-string|null
     */
    public function findEnclosingClassFromContent(string $content, int $line): ?string
    {
        $lines = explode("\n", $content);

        $classPattern = '/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/i';
        for ($i = $line; $i >= 0; $i--) {
            $lineText = $lines[$i] ?? '';
            if (preg_match($classPattern, $lineText, $matches) === 1) {
                $shortName = $matches[1];
                $namespace = $this->findNamespace($lines, $i);
                if ($namespace !== null) {
                    /** @var class-string */
                    return $namespace . '\\' . $shortName;
                }
                /** @var class-string */
                return $shortName;
            }
        }

        // Code outside any class - no enclosing class found
        return null;
    }

    /**
     * Find namespace declaration by scanning lines.
     *
     * @param list<string> $lines
     */
    public function findNamespace(array $lines, int $beforeLine): ?string
    {
        for ($i = $beforeLine - 1; $i >= 0; $i--) {
            $lineText = $lines[$i] ?? '';
            if (preg_match('/^\s*namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*[;{]/', $lineText, $matches) === 1) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Match the type text of a named parameter in the enclosing function-like
     * declaration reached by scanning backwards from the given line. Returns
     * the raw type token (e.g. "?User" or "Foo|Bar"), or null when no match
     * lands. Resolving each token to a {@see Type} is the caller's job.
     *
     * @param list<string> $lines
     */
    public function matchParameterType(array $lines, int $line, string $varName): ?string
    {
        for ($i = $line; $i >= 0; $i--) {
            $lineText = $lines[$i] ?? '';

            if (preg_match('/function\s+\w+\s*\(/', $lineText) === 1) {
                $declaration = $lineText;
                for ($j = $i; $j < min($i + 10, count($lines)); $j++) {
                    if ($j > $i) {
                        $declaration .= ' ' . $lines[$j];
                    }
                    if (str_contains($declaration, ')')) {
                        break;
                    }
                }

                $pattern = '/([?A-Za-z_\\\\][A-Za-z0-9_\\\\|?]*)\s+\$' . preg_quote($varName, '/') . '\b/';
                if (preg_match($pattern, $declaration, $matches) === 1) {
                    return $matches[1];
                }
                return null;
            }
        }
        return null;
    }

    /**
     * @param array<Stmt> $ast
     * @return ?class-string
     */
    public function resolveEnclosingClassName(
        array $ast,
        int $offset,
        string $content,
        int $line,
    ): ?string {
        $classLike = Scope::atOffset($ast, $offset)->getEnclosingClassLike();
        if ($classLike !== null) {
            return ScopeFinder::getClassLikeName($classLike);
        }
        return $this->findEnclosingClassFromContent($content, $line);
    }
}
