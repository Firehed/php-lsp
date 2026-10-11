<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\NodeLocator;

use Firehed\PhpLsp\Parser\BuildsWrittenNamesTrait;
use Firehed\PhpLsp\Parser\Lexer\LexerInterface;
use Firehed\PhpLsp\Parser\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\UseItem;
use PhpParser\Node\VarLikeIdentifier;

/**
 * Synthesizes the member-access or call node at the cursor from the document
 * text (RFC 1 §4.11).
 *
 * The synthesized node is resolved and linked into the parsed document's tree,
 * so it meets the same contract as a parsed node.
 */
final class CursorTextNodeLocator implements NodeLocatorInterface
{
    use BuildsWrittenNamesTrait;

    private const string NON_FUNCTION_KEYWORD_PATTERN
        = '/\A(?:if|while|for|foreach|switch|catch|array|list|function|fn)\z/i';

    /**
     * An arrow function's head, through its `=>`. A parameter default may hold
     * parentheses, so they are matched in balanced pairs.
     */
    private const string ARROW_FUNCTION_HEAD = '/\A\s*(?:static\s+)?fn\s*&?\s*(\((?:[^()]++|(?1))*\))[^=]*=>/i';

    /** Text ending here makes the name that follows a declaration, not a call. */
    private const string DECLARES_NEXT_NAME_PATTERN = '/\bfunction\s*&?\s*\z/i';

    /**
     * A name as written: optionally fully qualified, never empty, never ending
     * in a separator.
     */
    private const string NAME = '\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';

    private readonly NodeAtPosition $nodeAtPosition;
    private readonly TreeAnnotator $annotator;

    public function __construct(
        private readonly LexerInterface $lexer,
    ) {
        $this->nodeAtPosition = new NodeAtPosition();
        $this->annotator = new TreeAnnotator(tolerant: true);
    }

    public function nodeAt(ParsedDocument $parsed, int $offset): ?Node
    {
        $document = $parsed->document;
        if ($offset < 0 || $offset > strlen($document->getContent())) {
            return null;
        }

        $line = $document->positionAt($offset)['line'];
        $lineStart = $document->offsetAt($line, 0);
        $lineText = $document->getLine($line);
        $content = $document->getContent();

        $memberNode = self::synthesizeMemberAccess($lineText, $lineStart, $offset, $line);
        $callNode = $this->synthesizeCall($content, $offset, $line, $memberNode);

        $root = $callNode ?? $memberNode;
        if ($root === null) {
            return null;
        }
        $this->attach($root, $parsed->tree);

        return $this->nodeAtPosition->find([$root], $offset);
    }

    /**
     * Resolves the fragment's names against the namespace and imports in
     * effect where it starts, through the annotator every tree source uses,
     * then links it under the innermost statement or function-like of $tree
     * that contains it.
     *
     * @param array<Stmt> $tree
     */
    private function attach(Expr|Attribute $root, array $tree): void
    {
        $start = $root->getStartFilePos();
        $namespace = null;
        foreach ($tree as $stmt) {
            if ($stmt instanceof Stmt\Namespace_ && $stmt->getStartFilePos() <= $start) {
                $namespace = $stmt;
            }
        }

        $body = [];
        foreach ($namespace->stmts ?? $tree as $stmt) {
            if ($stmt->getStartFilePos() > $start) {
                break;
            }
            if ($stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse) {
                $body[] = self::copyImport($stmt);
            }
        }
        // The root sits in the node that holds its kind in a parsed tree, at the
        // root's position. Php-parser resolves an attribute's name only within a
        // declaration's attribute groups, so for annotation the group is held by
        // a stand-in declaration.
        // Until annotation links it, the root carries only its position.
        $position = $root->getAttributes();
        if ($root instanceof Attribute) {
            $holder = new AttributeGroup([$root], $position);
            $body[] = new Stmt\Function_('_', ['attrGroups' => [$holder]]);
        } else {
            $holder = new Stmt\Expression($root, $position);
            $body[] = $holder;
        }
        $this->annotator->annotate($namespace === null ? $body : [
            new Stmt\Namespace_($namespace->name === null ? null : new Name($namespace->name->name), $body),
        ], []);

        $holder->setAttribute('parent', $this->nodeAtPosition->find(
            $tree,
            $start,
            fn (Node $node) => $node instanceof Stmt || $node instanceof FunctionLike,
        ));
    }

    /**
     * A fresh copy, so annotating it leaves the document's own tree untouched.
     */
    private static function copyImport(Stmt\Use_|Stmt\GroupUse $import): Stmt\Use_|Stmt\GroupUse
    {
        $uses = array_map(
            fn (UseItem $use) => new UseItem(new Name($use->name->name), $use->alias?->name, $use->type),
            $import->uses,
        );

        return $import instanceof Stmt\GroupUse
            ? new Stmt\GroupUse(new Name($import->prefix->name), $uses, $import->type)
            : new Stmt\Use_($uses, $import->type);
    }

    /**
     * The instance and static member-access regexes, spelled out at their
     * two homes so a reader can read either without hopping. Chain access
     * (`$this->x->y->prefix`) reuses the instance path on the leaf `$var->`
     * segment: the source is a cursor-position primitive, not a chain typer.
     */
    private static function synthesizeMemberAccess(
        string $lineText,
        int $lineStart,
        int $offset,
        int $line,
    ): PropertyFetch|StaticPropertyFetch|null {
        // Static: ClasslikeName::prefix, excluding $var::.
        if (
            preg_match_all(
                '/(?<![\w\\\\$])(' . self::NAME . ')(::)(\w*)/',
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
     * up from the member-access still reaches the enclosing call.
     */
    private function synthesizeCall(
        string $content,
        int $offset,
        int $line,
        ?Node $memberInside,
    ): FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute|null {
        $code = $this->lexer->codeOnly(substr($content, 0, $offset));
        $parenPos = self::findUnclosedParen($code);
        if ($parenPos === null) {
            return null;
        }

        $callNode = self::buildCallFrame(substr($code, 0, $parenPos), $parenPos, $line);
        if ($callNode === null) {
            return null;
        }

        [$args, $separators] = self::parseArgs($content, $code, $parenPos + 1, $line, $memberInside);
        $callNode->args = $args;
        $callNode->setAttribute(ParsedDocument::ARGUMENT_SEPARATORS, $separators);

        $callStart = $callNode->getStartFilePos();
        $callNode->setAttribute('endFilePos', max($callStart, $offset));

        return $callNode;
    }

    private static function findUnclosedParen(string $code): ?int
    {
        $depth = 0;
        for ($i = strlen($code) - 1; $i >= 0; $i--) {
            $char = $code[$i];
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
                '/#\[\s*(?:[\w\\\\]+\s*,\s*)*(' . self::NAME . ')\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $nameText = $m[1][0];
            $nameStart = $m[1][1];
            $name = self::writtenName($nameText, $nameStart, $line);
            return new Attribute($name, [], self::posAttrs($nameStart, $lastByte, $line));
        }

        if (
            preg_match(
                '/(?<![\w\\\\])(' . self::NAME . ')::(\w+)\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $classText = $m[1][0];
            $classStart = $m[1][1];
            $methodText = $m[2][0];
            $methodStart = $m[2][1];
            $class = self::writtenName($classText, $classStart, $line);
            $method = new Identifier(
                $methodText,
                self::posAttrs($methodStart, $methodStart + strlen($methodText) - 1, $line),
            );
            return new StaticCall($class, $method, [], self::posAttrs($classStart, $lastByte, $line));
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
            return $isNullsafe
                ? new NullsafeMethodCall($var, $method, [], self::posAttrs($varStart, $lastByte, $line))
                : new MethodCall($var, $method, [], self::posAttrs($varStart, $lastByte, $line));
        }

        if (
            preg_match(
                '/\bnew\s+(' . self::NAME . ')\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $nameText = $m[1][0];
            $nameStart = $m[1][1];
            $newStart = $m[0][1];
            return new New_(
                self::writtenName($nameText, $nameStart, $line),
                [],
                self::posAttrs($newStart, $lastByte, $line),
            );
        }

        if (
            preg_match(
                '/(?<![\w\\\\:])(' . self::NAME . ')\s*$/',
                $text,
                $m,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $funcName = $m[1][0];
            $funcStart = $m[1][1];
            if (
                preg_match(self::NON_FUNCTION_KEYWORD_PATTERN, $funcName) === 1
                || preg_match(self::DECLARES_NEXT_NAME_PATTERN, substr($text, 0, $funcStart)) === 1
            ) {
                return null;
            }
            return new FuncCall(
                self::writtenName($funcName, $funcStart, $line),
                [],
                self::posAttrs($funcStart, $lastByte, $line),
            );
        }

        return null;
    }

    /**
     * A name as php-parser models how it is written: a leading `\` makes it
     * fully qualified and a leading `namespace\` makes it relative, each
     * stored without that prefix.
     */
    private static function writtenName(string $written, int $startFilePos, int $line): Name
    {
        return self::nameAsWritten(
            $written,
            self::posAttrs($startFilePos, $startFilePos + strlen($written) - 1, $line),
        );
    }

    /**
     * The arguments from $argsStart to the cursor, split at the commas in
     * $code and read from $content.
     *
     * @param string $code $content up to the cursor, as {@see LexerInterface::codeOnly()} returns it
     * @return array{list<Arg>, list<int>} The arguments and the positions of
     *         the commas between them
     */
    private static function parseArgs(
        string $content,
        string $code,
        int $argsStart,
        int $line,
        ?Node $memberInside,
    ): array {
        $args = [];
        $separators = [];
        $depth = 0;
        $currentStart = $argsStart;
        $offset = strlen($code);
        for ($i = $argsStart; $i < $offset; $i++) {
            $char = $code[$i];
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $separators[] = $i;
                $segment = substr($content, $currentStart, $i - $currentStart);
                $arg = self::buildArg($segment, $currentStart, $i - 1, $line, null);
                if ($arg !== null) {
                    $args[] = $arg;
                }
                $currentStart = $i + 1;
            }
        }

        $lastSegment = substr($content, $currentStart, $offset - $currentStart);
        $lastStart = $currentStart;
        $lastEnd = $offset;
        // Deliberately no end-position check: a nodeAt at an earlier offset
        // inside `$var` still needs to descend into the member access.
        $inner = ($memberInside !== null && $memberInside->getStartFilePos() >= $lastStart)
            ? $memberInside
            : null;

        if (preg_match(self::ARROW_FUNCTION_HEAD, substr($code, $lastStart), $head) === 1) {
            $bodyStart = $lastStart + strlen($head[0]);
            $args[] = self::arrowFunctionArg($content, $lastStart, $bodyStart, $offset, $line, $inner);
            return [$args, $separators];
        }

        $arg = self::buildArg($lastSegment, $lastStart, $lastEnd, $line, $inner);
        if ($arg !== null) {
            $args[] = $arg;
        }

        return [$args, $separators];
    }

    /**
     * The argument being typed when the cursor is in an arrow function's
     * body, which sits inside the function as it does in a parsed tree.
     */
    private static function arrowFunctionArg(
        string $content,
        int $start,
        int $bodyStart,
        int $offset,
        int $line,
        ?Node $memberInside,
    ): Arg {
        $body = $memberInside instanceof Expr
            ? $memberInside
            : self::typedValue(substr($content, $bodyStart, $offset - $bodyStart), $bodyStart, $offset, $line);
        $attrs = self::posAttrs($start, $offset, $line);

        return new Arg(new Expr\ArrowFunction(['expr' => $body], $attrs), false, false, $attrs);
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

        $value = match (true) {
            $memberInside instanceof \PhpParser\Node\Expr => $memberInside,
            $named === null => self::typedValue($segment, $segStart, $segEnd, $line),
            default => new Variable('_', self::posAttrs($segStart, $segEnd, $line)),
        };

        return new Arg(
            $value,
            false,
            false,
            self::posAttrs($segStart, $segEnd, $line),
            $named,
        );
    }

    /**
     * A positional argument's value as typed so far, as far as completion
     * reads it: a bare word, which may yet become a name, or a variable; any
     * other text is a placeholder spanning the segment.
     */
    private static function typedValue(string $segment, int $segStart, int $segEnd, int $line): Expr
    {
        $trimmed = trim($segment);
        $start = $segStart + strlen($segment) - strlen(ltrim($segment));
        $attrs = self::posAttrs($start, $start + strlen($trimmed) - 1, $line);
        if (preg_match('/^[A-Za-z_]\w*$/', $trimmed) === 1) {
            return new Expr\ConstFetch(new Name($trimmed, $attrs), $attrs);
        }
        if (preg_match('/^\$(\w+)$/', $trimmed, $m) === 1) {
            return new Variable($m[1], $attrs);
        }
        return new Variable('_', self::posAttrs($segStart, $segEnd, $line));
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
            $currentReceiver = new PropertyFetch(
                $currentReceiver,
                $segIdent,
                self::posAttrs($varStart, $segNameEnd, $line),
            );
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

        return new PropertyFetch(
            $currentReceiver,
            $name,
            self::posAttrs($varStart, $matchEnd, $line),
        );
    }

    /**
     * @param array<int, array{0: string, 1: int}> $m
     */
    private static function buildStatic(array $m, int $matchStart, int $line, int $lineStart): StaticPropertyFetch
    {
        // The regex captured `ClasslikeName::prefix`. Each `*Start`/`*End` names the
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

        $classNode = self::writtenName($rawClass, $classStart, $line);
        $name = $prefix === ''
            ? new Error(self::posAttrs($colonsEnd + 1, $colonsEnd + 1, $line))
            : new VarLikeIdentifier($prefix, self::posAttrs($prefixStart, $prefixEnd, $line));

        return new StaticPropertyFetch(
            $classNode,
            $name,
            self::posAttrs($classStart, $matchEnd, $line),
        );
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
