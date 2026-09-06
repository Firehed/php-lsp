<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeAtPosition;
use PhpParser\Node;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\VarLikeIdentifier;

/**
 * The text-only {@see SyntaxSource} for the position at the cursor. `parse()`
 * yields nothing — the source has no tree of its own — and `nodeAt()` ignores
 * the tree it is handed and synthesizes a member-access node from the document
 * text at the cursor. Placed last in the composite, it answers only when every
 * earlier member has answered null (RFC 1 §4.11, build-manifest step-40).
 *
 * A `$var->prefix` at the cursor becomes a {@see PropertyFetch} with a
 * {@see Variable} receiver; a `ClassName::prefix` becomes a
 * {@see StaticPropertyFetch} with a {@see Name} receiver; the name is
 * {@see Error} when no identifier follows the arrow or the colons. Synthesized
 * nodes carry `startFilePos`, `endFilePos`, and `startLine` from the match
 * offsets and carry no `parent` attribute, so a downstream reader that needs
 * the enclosing class-like reads it from the node's position rather than the
 * parent chain.
 */
final class CursorTextSyntaxSource implements SyntaxSource
{
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

        $stmt = self::synthesize($lineText, $lineStart, $offset, $line);
        if ($stmt === null) {
            return null;
        }
        return $this->nodeAtPosition->find([$stmt], $offset);
    }

    /**
     * The instance and static member-access regexes, spelled out at their
     * two homes so a reader can read either without hopping. Chain access
     * (`$this->x->y->prefix`) reuses the instance path on the leaf `$var->`
     * segment: the source is a cursor-position primitive, not a chain typer.
     */
    private static function synthesize(string $lineText, int $lineStart, int $offset, int $line): ?Node
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
        if ($chainText !== '') {
            $chainAnchor = $lineStart + $chainOffsetInLine;
            $chainSegments = [];
            if (
                preg_match_all(
                    '/(\??->)(\w+)/',
                    $chainText,
                    $chainSegments,
                    PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
                ) > 0
            ) {
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
            }
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
        $classEnd = $classStart + strlen($rawClass) - 1;
        $colonsStart = $lineStart + $m[2][1];
        $colonsEnd = $colonsStart + 1;
        $prefixStart = $lineStart + $m[3][1];
        $prefixEnd = $prefixStart + max(0, strlen($prefix) - 1);
        $matchEnd = $prefix === '' ? $colonsEnd : $prefixEnd;

        // Php-parser drops the leading separator on a fully qualified name and
        // records the fully qualified flag; keep the same shape here.
        $className = ltrim($rawClass, '\\');
        $classAttrs = self::posAttrs($classStart, $classEnd, $line);
        $classNode = $rawClass !== $className
            ? new FullyQualified($className, $classAttrs)
            : new Name($className, $classAttrs);
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
