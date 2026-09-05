<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\NameCase;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Text-based fallback for code resolution when AST-based detection fails.
 *
 * Handles cases where PHP-Parser drops incomplete code (e.g., `if ($this->|)`).
 * Uses regex patterns to detect member access and extract information.
 *
 * @internal
 */
final class TextFallbackHelper
{
    /**
     * Match the member-access pattern that ends the given text (typically the
     * source line before the cursor). Returns a typed match struct describing
     * the receiver kind, or null if no member-access pattern is at the tail.
     *
     * This is a text primitive: it holds the regex and nothing else. Resolving
     * the match to a {@see MemberAccessContext} is the caller's job.
     *
     * @return array{kind: 'chain', chain: string, prefix: string}
     *      | array{kind: 'instance', var: string, prefix: string}
     *      | array{kind: 'static', class: string, prefix: string}
     *      | null
     */
    public function matchMemberAccessAt(string $textBeforeCursor): ?array
    {
        // Chained instance access: $this->member->prefix or $this?->member->prefix
        if (preg_match('/(\$this(?:\??->[\w]+(?:\([^)]*\))?)+)\??->([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return ['kind' => 'chain', 'chain' => $m[1], 'prefix' => $m[2]];
        }

        // Simple instance access: $var->prefix or $var?->prefix
        if (preg_match('/\$(\w+)(\?)?->([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return ['kind' => 'instance', 'var' => $m[1], 'prefix' => $m[3]];
        }

        // Static access: ClassName::prefix (excluding $var::)
        if (preg_match('/(?<!\$)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return ['kind' => 'static', 'class' => $m[1], 'prefix' => $m[2]];
        }

        return null;
    }

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
     * Detect a call from text when AST-based detection fails.
     *
     * @param array<Stmt> $ast
     * @return array{
     *   0: FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute,
     *   1: int,
     *   2: list<string>,
     *   3: int,
     * }|null
     */
    public function detectCallFromText(array $ast, int $offset, string $content, int $line): ?array
    {
        $parenPos = self::findUnclosedParen($content, $offset);
        if ($parenPos === null) {
            return null;
        }

        $textBeforeParen = substr($content, 0, $parenPos);

        $callNode = $this->parseCallPattern($textBeforeParen, $ast, $offset, $line, $content);
        if ($callNode === null) {
            return null;
        }

        $argsText = substr($content, $parenPos + 1, $offset - $parenPos - 1);
        [$activeParam, $usedNames, $positionalCount] = self::parseArgsFromText($argsText);

        return [$callNode, $activeParam, $usedNames, $positionalCount];
    }

    private static function findUnclosedParen(string $content, int $offset): ?int
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $content[$i];
            if ($char === ')') {
                $depth++;
            } elseif ($char === '(') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            } elseif ($char === ';' || $char === '{' || $char === '}') {
                return null;
            }
        }
        return null;
    }

    /**
     * @param array<Stmt> $ast
     * @return FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute|null
     */
    private function parseCallPattern(
        string $textBeforeParen,
        array $ast,
        int $offset,
        int $line,
        string $content,
    ): FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute|null {
        $text = rtrim($textBeforeParen);
        $context = NameContextFactory::fromAst($ast, $line);

        if (preg_match('/#\[\s*(?:[\w\\\\]+\s*,\s*)*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*$/', $text, $m) === 1) {
            return new Attribute(self::resolvedName($m[1], $context));
        }

        if (preg_match('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::(\w+)\s*$/', $text, $m) === 1) {
            return new StaticCall(self::resolvedName($m[1], $context), new Identifier($m[2]));
        }

        if (preg_match('/\$(\w+)(\?)?->(\w+)\s*$/', $text, $m) === 1) {
            $varName = $m[1];
            $isNullsafe = $m[2] === '?';
            $methodName = $m[3];
            $var = new Variable($varName);
            if ($varName === 'this') {
                EnclosingClassResolver::seedThisPosition($var, $line, $offset);
            }
            return $isNullsafe
                ? new NullsafeMethodCall($var, new Identifier($methodName))
                : new MethodCall($var, new Identifier($methodName));
        }

        if (preg_match('/\bnew\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*$/', $text, $m) === 1) {
            return new New_(self::resolvedName($m[1], $context));
        }

        if (preg_match('/\b(\w+)\s*$/', $text, $m) === 1) {
            $funcName = $m[1];
            $keywords = ['if', 'while', 'for', 'foreach', 'switch', 'catch', 'array', 'list'];
            if (!in_array(NameCase::Insensitive->normalize($funcName), $keywords, true)) {
                return new FuncCall(new Name($funcName, ['startLine' => $line + 1]));
            }
        }

        return null;
    }

    private static function resolvedName(string $short, NameContext $context): Name
    {
        $name = new Name($short);
        $fqn = $context->candidates($short, NameKind::ClassLike)[0];
        if ($fqn !== $short) {
            $name->setAttribute('resolvedName', new Name\FullyQualified($fqn));
        }
        return $name;
    }

    /**
     * @return array{0: int, 1: list<string>, 2: int}
     */
    private static function parseArgsFromText(string $argsText): array
    {
        $activeParam = 0;
        $usedNames = [];
        $positionalCount = 0;
        $sawNamedArg = false;

        $depth = 0;
        $currentArg = '';

        for ($i = 0; $i < strlen($argsText); $i++) {
            $char = $argsText[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                $currentArg .= $char;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
                $currentArg .= $char;
            } elseif ($char === ',' && $depth === 0) {
                self::processArgText($currentArg, $usedNames, $positionalCount, $sawNamedArg);
                $activeParam++;
                $currentArg = '';
            } else {
                $currentArg .= $char;
            }
        }

        if (preg_match('/^(\w+)\s*:/', trim($currentArg), $m) === 1) {
            $usedNames[] = $m[1];
        }

        return [$activeParam, $usedNames, $positionalCount];
    }

    /**
     * @param list<string> $usedNames
     */
    private static function processArgText(
        string $argText,
        array &$usedNames,
        int &$positionalCount,
        bool &$sawNamedArg,
    ): void {
        $argText = trim($argText);
        if ($argText === '') {
            return;
        }

        if (preg_match('/^(\w+)\s*:/', $argText, $m) === 1) {
            $usedNames[] = $m[1];
            $sawNamedArg = true;
        } elseif (!$sawNamedArg) {
            $positionalCount++;
        }
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
