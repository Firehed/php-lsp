<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeAtPosition;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\VarLikeIdentifier;

/**
 * Synthesizes the member-access or call node at the cursor from the document
 * text. `parse()` yields nothing; `nodeAt()` ignores the tree it is handed.
 * Placed last in the composite, so it answers only when every earlier member
 * has answered null (RFC 1 §4.11).
 *
 * The outer node has no `parent` attribute set, so a downstream reader that
 * needs the enclosing class-like reads it from the node's position rather
 * than the parent chain.
 */
final class CursorTextSyntaxSource implements SyntaxSourceInterface
{
    private const string NON_FUNCTION_KEYWORD_PATTERN
        = '/\A(?:if|while|for|foreach|switch|catch|array|list)\z/i';

    private readonly NodeAtPosition $nodeAtPosition;

    public function __construct()
    {
        $this->nodeAtPosition = new NodeAtPosition();
    }

    /**
     * @return array<Stmt>
     */
    public function parse(TextDocument $document): array
    {
        return [];
    }

    /**
     * @param array<Stmt> $tree
     */
    public function nodeAt(array $tree, TextDocument $document, int $offset): ?Node
    {
        if ($offset < 0 || $offset > strlen($document->getContent())) {
            return null;
        }

        $line = $document->positionAt($offset)['line'];
        $lineStart = $document->offsetAt($line, 0);
        $lineText = $document->getLine($line);
        $content = $document->getContent();

        $memberNode = self::synthesizeMemberAccess($lineText, $lineStart, $offset, $line);
        $callNode = self::synthesizeCall($content, $offset, $line, $memberNode);

        $root = $callNode ?? $memberNode;
        if ($root === null) {
            return null;
        }
        return $this->nodeAtPosition->find([$root], $offset);
    }

    /**
     * The instance and static member-access regexes, spelled out at their
     * two homes so a reader can read either without hopping. Chain access
     * (`$this->x->y->prefix`) reuses the instance path on the leaf `$var->`
     * segment: the source is a cursor-position primitive, not a chain typer.
     */
    private static function synthesizeMemberAccess(string $lineText, int $lineStart, int $offset, int $line): ?Node
    {
        // Static: ClassName::prefix, excluding $var::.
        if (
            preg_match_all(
                '/(?<!\$)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(::)(\w*)/',
                $lineText,
                $staticMatches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
            ) > 0
        ) {
            foreach (array_reverse($staticMatches) as $m) {
                $matchStart = $lineStart + $m[0][1];
                $matchEnd = $matchStart + strlen($m[0][0]);
                if ($offset >= $matchStart && $offset <= $matchEnd) {
                    return self::buildStatic($m, $matchStart, $line, $lineStart);
                }
            }
        }

        // Instance: `$var(->prop)*->prefix`. Chain segments become an inner
        // PropertyFetch tree so a downstream reader that types the receiver
        // walks the same shape it would from a real AST.
        if (
            preg_match_all(
                '/\$(\w+)((?:\??->\w+)*)(\??->)(\w*)/',
                $lineText,
                $instanceMatches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
            ) > 0
        ) {
            foreach (array_reverse($instanceMatches) as $m) {
                $matchStart = $lineStart + $m[0][1];
                $matchEnd = $matchStart + strlen($m[0][0]);
                if ($offset >= $matchStart && $offset <= $matchEnd) {
                    return self::buildInstance($m, $matchStart, $line, $lineStart);
                }
            }
        }

        return null;
    }

    /**
     * `$memberInside` becomes the value of the trailing {@see Arg} so a walk
     * up from the member-access still reaches the enclosing call. Class-like
     * receivers get no `resolvedName`: `NameContext` sits in the Resolution
     * layer, out of reach; a downstream reader fills it in.
     */
    private static function synthesizeCall(
        string $content,
        int $offset,
        int $line,
        ?Node $memberInside,
    ): ?Node {
        $parenPos = self::findUnclosedParen($content, $offset);
        if ($parenPos === null) {
            return null;
        }

        $textBeforeParen = substr($content, 0, $parenPos);
        $callNode = self::buildCallFrame($textBeforeParen, $parenPos, $line);
        if ($callNode === null) {
            return null;
        }

        $argsText = substr($content, $parenPos + 1, $offset - $parenPos - 1);
        $args = self::parseArgs($argsText, $parenPos + 1, $offset, $line, $memberInside);
        $callNode->args = $args;
        foreach ($args as $arg) {
            $arg->setAttribute('parent', $callNode);
        }

        $callStart = $callNode->getStartFilePos();
        $callNode->setAttribute('endFilePos', max($callStart, $offset));

        return $callNode;
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

    private static function buildCallFrame(
        string $textBeforeParen,
        int $parenPos,
        int $line,
    ): FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute|null {
        $text = rtrim($textBeforeParen);
        $lastByte = $parenPos - 1;

        if (
            preg_match(
                '/#\[\s*(?:[\w\\\\]+\s*,\s*)*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $nameText = $m[1][0];
            $nameStart = $m[1][1];
            $name = self::classLikeName($nameText, $nameStart, $line);
            $attr = new Attribute($name, [], self::posAttrs($nameStart, $lastByte, $line));
            $name->setAttribute('parent', $attr);
            return $attr;
        }

        if (
            preg_match(
                '/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::(\w+)\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $classText = $m[1][0];
            $classStart = $m[1][1];
            $methodText = $m[2][0];
            $methodStart = $m[2][1];
            $class = self::classLikeName($classText, $classStart, $line);
            $method = new Identifier(
                $methodText,
                self::posAttrs($methodStart, $methodStart + strlen($methodText) - 1, $line),
            );
            $call = new StaticCall($class, $method, [], self::posAttrs($classStart, $lastByte, $line));
            $class->setAttribute('parent', $call);
            $method->setAttribute('parent', $call);
            return $call;
        }

        if (
            preg_match(
                '/\$(\w+)(\?)?->(\w+)\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $varName = $m[1][0];
            $varNameStart = $m[1][1];
            $varStart = $varNameStart - 1;
            $isNullsafe = $m[2][0] === '?';
            $methodName = $m[3][0];
            $methodStart = $m[3][1];
            $var = new Variable(
                $varName,
                self::posAttrs($varStart, $varNameStart + strlen($varName) - 1, $line),
            );
            $method = new Identifier(
                $methodName,
                self::posAttrs($methodStart, $methodStart + strlen($methodName) - 1, $line),
            );
            $call = $isNullsafe
                ? new NullsafeMethodCall($var, $method, [], self::posAttrs($varStart, $lastByte, $line))
                : new MethodCall($var, $method, [], self::posAttrs($varStart, $lastByte, $line));
            $var->setAttribute('parent', $call);
            $method->setAttribute('parent', $call);
            return $call;
        }

        if (
            preg_match(
                '/\bnew\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $nameText = $m[1][0];
            $nameStart = $m[1][1];
            $newStart = $m[0][1];
            $name = self::classLikeName($nameText, $nameStart, $line);
            $new = new New_($name, [], self::posAttrs($newStart, $lastByte, $line));
            $name->setAttribute('parent', $new);
            return $new;
        }

        if (
            preg_match(
                '/\b(\w+)\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $funcName = $m[1][0];
            $funcStart = $m[1][1];
            if (preg_match(self::NON_FUNCTION_KEYWORD_PATTERN, $funcName) === 1) {
                return null;
            }
            $name = new Name(
                $funcName,
                self::posAttrs($funcStart, $funcStart + strlen($funcName) - 1, $line),
            );
            $call = new FuncCall($name, [], self::posAttrs($funcStart, $lastByte, $line));
            $name->setAttribute('parent', $call);
            return $call;
        }

        return null;
    }

    /**
     * Php-parser drops the leading `\` on a fully-qualified name; the same is
     * done here so a downstream reader sees one shape.
     */
    private static function classLikeName(string $short, int $startFilePos, int $line): Name
    {
        $normalized = ltrim($short, '\\');
        $attrs = self::posAttrs($startFilePos, $startFilePos + strlen($short) - 1, $line);
        return $short !== $normalized
            ? new FullyQualified($normalized, $attrs)
            : new Name($normalized, $attrs);
    }

    /**
     * @return list<Arg>
     */
    private static function parseArgs(
        string $argsText,
        int $argsStart,
        int $offset,
        int $line,
        ?Node $memberInside,
    ): array {
        $args = [];
        $depth = 0;
        $currentStart = 0;
        $length = strlen($argsText);
        for ($i = 0; $i < $length; $i++) {
            $char = $argsText[$i];
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $segment = substr($argsText, $currentStart, $i - $currentStart);
                $segStart = $argsStart + $currentStart;
                $segEnd = $argsStart + $i - 1;
                $arg = self::buildArg($segment, $segStart, $segEnd, $line, null);
                if ($arg !== null) {
                    $args[] = $arg;
                }
                $currentStart = $i + 1;
            }
        }

        $lastSegment = substr($argsText, $currentStart);
        $lastStart = $argsStart + $currentStart;
        $lastEnd = $offset;
        $trimmed = trim($lastSegment);
        $hasNamed = $trimmed !== '' && preg_match('/^(\w+)\s*:/', $trimmed) === 1;
        // Deliberately no end-position check: a nodeAt at an earlier offset
        // inside `$var` still needs to descend into the member access.
        $inner = ($memberInside !== null && $memberInside->getStartFilePos() >= $lastStart)
            ? $memberInside
            : null;

        if ($hasNamed || $inner !== null) {
            $arg = self::buildArg($lastSegment, $lastStart, $lastEnd, $line, $inner);
            if ($arg !== null) {
                $args[] = $arg;
            }
        }

        return $args;
    }

    /**
     * Null when the segment has no name, no expression, and no cursor content:
     * a bare `,` before the cursor must not become a phantom positional arg.
     */
    private static function buildArg(
        string $segment,
        int $segStart,
        int $segEnd,
        int $line,
        ?Node $memberInside,
    ): ?Arg {
        $trimmed = trim($segment);
        $named = null;
        if ($trimmed !== '' && preg_match('/^(\w+)\s*:/', $trimmed, $m) === 1) {
            $nameOffsetInSegment = strpos($segment, $m[1]);
            $nameStart = $segStart + ($nameOffsetInSegment === false ? 0 : $nameOffsetInSegment);
            $named = new Identifier(
                $m[1],
                self::posAttrs($nameStart, $nameStart + strlen($m[1]) - 1, $line),
            );
        }

        if ($named === null && $trimmed === '' && $memberInside === null) {
            return null;
        }

        $value = $memberInside instanceof \PhpParser\Node\Expr
            ? $memberInside
            : new Variable('_', self::posAttrs($segStart, $segEnd, $line));

        $arg = new Arg(
            $value,
            false,
            false,
            self::posAttrs($segStart, $segEnd, $line),
            $named,
        );
        $named?->setAttribute('parent', $arg);
        $value->setAttribute('parent', $arg);

        return $arg;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $m
     */
    private static function buildInstance(array $m, int $matchStart, int $line, int $lineStart): PropertyFetch
    {
        $varName = $m[1][0];
        $chainText = $m[2][0];
        $chainOffsetInLine = $m[2][1];
        $arrowText = $m[3][0];
        $prefix = $m[4][0];
        $varStart = $matchStart;
        // `$this` is 5 chars: $ at matchStart, s (last) at matchStart+4 = matchStart+strlen('this').
        $varEnd = $varStart + strlen($varName);

        $receiver = new Variable(
            $varName,
            self::posAttrs($varStart, $varEnd, $line),
        );
        $currentReceiver = $receiver;
        $chainAnchor = $lineStart + $chainOffsetInLine;
        preg_match_all(
            '/(\??->)(\w+)/',
            $chainText,
            $chainSegments,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );
        foreach ($chainSegments as $seg) {
            $segName = $seg[2][0];
            $segNameStart = $chainAnchor + $seg[2][1];
            $segNameEnd = $segNameStart + strlen($segName) - 1;
            $segIdent = new Identifier(
                $segName,
                self::posAttrs($segNameStart, $segNameEnd, $line),
            );
            $inner = new PropertyFetch(
                $currentReceiver,
                $segIdent,
                self::posAttrs($varStart, $segNameEnd, $line),
            );
            $currentReceiver->setAttribute('parent', $inner);
            $segIdent->setAttribute('parent', $inner);
            $currentReceiver = $inner;
        }

        // Absolute file offsets of the arrow (`->` or `?->`) and the identifier
        // prefix after it. Regex offsets are line-relative; `$lineStart` shifts
        // them to file coordinates. `endFilePos` is inclusive; `max(0, ...)`
        // keeps an empty prefix from stepping past its start.
        $arrowStart = $lineStart + $m[3][1];
        $arrowEnd = $arrowStart + strlen($arrowText) - 1;
        $prefixStart = $lineStart + $m[4][1];
        $prefixEnd = $prefixStart + max(0, strlen($prefix) - 1);
        $matchEnd = $prefix === '' ? $arrowEnd : $prefixEnd;

        $name = $prefix === ''
            ? new Error(self::posAttrs($arrowEnd + 1, $arrowEnd + 1, $line))
            : new Identifier($prefix, self::posAttrs($prefixStart, $prefixEnd, $line));

        $fetch = new PropertyFetch(
            $currentReceiver,
            $name,
            self::posAttrs($varStart, $matchEnd, $line),
        );
        // Inner children carry a parent so a downstream reader that walked the
        // Identifier/Error → outer expression edge still finds the fetch. The
        // outer node has no parent, so enclosing-class lookup falls onto the
        // node's file position rather than a parent chain (RFC 1 §4.11).
        $currentReceiver->setAttribute('parent', $fetch);
        $name->setAttribute('parent', $fetch);
        return $fetch;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $m
     */
    private static function buildStatic(array $m, int $matchStart, int $line, int $lineStart): StaticPropertyFetch
    {
        // The regex captured `ClassName::prefix`. Each `*Start`/`*End` names the
        // absolute file offset of one segment: the class name, the `::` pair,
        // and the (possibly empty) identifier prefix after it. `endFilePos` in
        // php-parser is inclusive — the offset of the last byte — so the `- 1`
        // and `max(0, ...)` guards handle empty captures without walking past
        // the start.
        $rawClass = $m[1][0];
        $prefix = $m[3][0];
        $classStart = $matchStart;
        $colonsStart = $lineStart + $m[2][1];
        $colonsEnd = $colonsStart + 1;
        $prefixStart = $lineStart + $m[3][1];
        $prefixEnd = $prefixStart + max(0, strlen($prefix) - 1);
        $matchEnd = $prefix === '' ? $colonsEnd : $prefixEnd;

        $classNode = self::classLikeName($rawClass, $classStart, $line);
        $name = $prefix === ''
            ? new Error(self::posAttrs($colonsEnd + 1, $colonsEnd + 1, $line))
            : new VarLikeIdentifier($prefix, self::posAttrs($prefixStart, $prefixEnd, $line));

        $fetch = new StaticPropertyFetch(
            $classNode,
            $name,
            self::posAttrs($classStart, $matchEnd, $line),
        );
        $classNode->setAttribute('parent', $fetch);
        $name->setAttribute('parent', $fetch);
        return $fetch;
    }

    /**
     * @return array{startFilePos: int, endFilePos: int, startLine: int}
     */
    private static function posAttrs(int $start, int $end, int $line): array
    {
        return [
            'startFilePos' => $start,
            'endFilePos' => $end,
            'startLine' => $line + 1,
        ];
    }
}
