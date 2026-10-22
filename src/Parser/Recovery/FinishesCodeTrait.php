<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * What code that stops mid-construct needs for php-parser to read it: a
 * stand-in word where an operand must follow, the closing quote of an
 * unterminated string, and the closers of every bracket still open.
 */
trait FinishesCodeTrait
{
    /**
     * Tokens after which an operand is complete, or none is needed.
     */
    private const array ENDS_OPERAND = [
        T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_VARIABLE,
        T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, ')', ']', '}', ';', '{', '"', T_OPEN_TAG,
    ];

    /**
     * The token a bracket-opening token is closed by.
     */
    private const array CLOSERS = [
        '(' => ')',
        '[' => ']',
        '{' => '}',
        T_ATTRIBUTE => ']',
        T_CURLY_OPEN => '}',
        T_DOLLAR_OPEN_CURLY_BRACES => '}',
    ];

    /**
     * Everything the code needs to be read: its unfinished operand or string
     * ended, then every open bracket closed.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function finish(array $tokens): string
    {
        [$standIn, $open, $previous] = self::unfinished($tokens);
        return $standIn . self::terminated($open, $previous);
    }

    /**
     * The text that ends the code's last unfinished operand or string, the
     * closers of the brackets still open (innermost last), and the text the
     * code ends with once the operand is ended.
     *
     * @param list<\PhpToken> $tokens
     * @return array{string, list<string>, ?string}
     */
    private static function unfinished(array $tokens): array
    {
        $open = [];
        $inString = false;
        $last = null;
        foreach ($tokens as $token) {
            if ($token->is('"')) {
                $inString = !$inString;
            }
            $closer = self::CLOSERS[$token->id] ?? self::CLOSERS[$token->text] ?? null;
            if ($closer !== null) {
                // A `for` header holds three expressions, so two semicolons;
                // a `match` subject is followed by its arms.
                $open[] = match (true) {
                    $token->is('(') && $last?->is(T_FOR) === true => ';;)',
                    $token->is('(') && $last?->is(T_MATCH) === true => ') {}',
                    default => $closer,
                };
            } elseif ($token->is([')', ']', '}'])) {
                array_pop($open);
            } elseif ($token->is(';') && $open !== [] && str_starts_with($open[count($open) - 1], ';')) {
                $open[count($open) - 1] = substr($open[count($open) - 1], 1);
            }
            if (!$token->isIgnorable() && $token->id !== 0) {
                $last = $token;
            }
        }
        $standIn = match (true) {
            $inString => '"',
            $last === null, $last->is(self::ENDS_OPERAND) => '',
            $last->is(T_ENCAPSED_AND_WHITESPACE) && str_starts_with($last->text, "'") => "'",
            $last->is(T_NS_SEPARATOR) => '_',
            default => ' _',
        };
        return [$standIn, $open, $standIn === '' ? $last?->text : $standIn];
    }

    /**
     * Moves what was read of finished code to where its code is in the
     * document. Each node the ending added takes no space, ending before it
     * starts where the code would be written; one that spans written code
     * ends where that code does.
     *
     * @param array<Node> $tree
     */
    private static function place(array $tree, int $by, int $lines, int $last): void
    {
        foreach ((new NodeFinder())->find($tree, static fn (): bool => true) as $node) {
            $attributes = $node->getAttributes();
            unset($attributes['startTokenPos'], $attributes['endTokenPos']);
            $start = $node->getStartFilePos() + $by;
            $attributes['startFilePos'] = min($start, $last + 1);
            $attributes['endFilePos'] = $start > $last ? $last : min($node->getEndFilePos() + $by, $last);
            foreach (['startLine', 'endLine'] as $key) {
                if (is_int($attributes[$key] ?? null)) {
                    $attributes[$key] += $lines;
                }
            }
            $separators = ParsedDocument::argumentSeparatorsOf($node);
            if ($separators !== null) {
                $attributes[ParsedDocument::ARGUMENT_SEPARATORS] = array_values(array_filter(
                    array_map(static fn (int $position): int => $position + $by, $separators),
                    static fn (int $position): bool => $position <= $last,
                ));
            }
            $comments = [];
            foreach ($node->getComments() as $comment) {
                $comments[] = new ($comment::class)(
                    $comment->getText(),
                    $comment->getStartLine() + $lines,
                    $comment->getStartFilePos() + $by,
                    -1,
                    $comment->getEndLine() + $lines,
                    $comment->getEndFilePos() + $by,
                    -1,
                );
            }
            if ($comments !== []) {
                $attributes['comments'] = $comments;
            }
            $node->setAttributes($attributes);
        }
    }

    /**
     * The closers, innermost first, with the statement before the first
     * closing brace ended.
     *
     * @param list<string> $open
     */
    private static function terminated(array $open, ?string $previous): string
    {
        $ending = '';
        foreach (array_reverse($open) as $closer) {
            if ($closer === '}' && $previous !== '}' && $previous !== '{' && $previous !== ';') {
                $ending .= ';';
            }
            $ending .= $closer;
            $previous = $closer;
        }
        return $ending;
    }
}
