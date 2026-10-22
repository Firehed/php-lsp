<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

use PhpParser\Error;
use PhpParser\Token;

/**
 * Where the code is in a document {@see GapGraftingRecovery} reads gaps of:
 * the positions of its tokens that are code, which brace closes which, and
 * where the parser reported errors. Tokens past the end of the document, which
 * an earlier recovery step may have added, are not part of it.
 */
final class CodeLayout
{
    private const array NOT_CODE = [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_INLINE_HTML];

    /**
     * What may sit between a header's last part and its body's brace: the
     * parentheses that close it, and for a function its parameter list's and
     * its return type.
     */
    private const array AFTER_HEADER = [')'];
    private const array AFTER_FUNCTION_HEADER = [
        ')', '(', ':', '?', '|', '&', T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
        T_STATIC, T_ARRAY, T_CALLABLE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
        T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
    ];

    /** @var list<int> */
    private array $positions = [];

    /** @var list<\PhpToken> */
    private array $tokens = [];

    /**
     * The position of each `{` that is closed, to the position of the `}`
     * that closes it.
     *
     * @var array<int, int>
     */
    private array $closes = [];

    /** @var list<int> */
    private array $errors = [];

    private int $codeStart = 0;

    /**
     * @param list<Token> $tokens
     * @param list<Error> $errors
     * @param bool $nested Whether this is the code of a gap read on its own,
     *        whose own gaps are read without reading further gaps in them
     */
    public function __construct(
        private readonly string $code,
        array $tokens,
        array $errors,
        public readonly bool $nested,
    ) {
        $length = strlen($code);
        $opened = [];
        foreach ($tokens as $token) {
            if ($token->pos >= $length) {
                break;
            }
            if ($token->is([T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO]) && $this->positions === []) {
                $this->codeStart = $token->pos + strlen($token->text);
            }
            if ($token->isIgnorable() || $token->is(self::NOT_CODE) || $token->id === 0) {
                continue;
            }
            $this->positions[] = $token->pos;
            $this->tokens[] = $token;
            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $opened[] = $token->pos;
            } elseif ($token->is('}')) {
                $open = array_pop($opened);
                if ($open !== null) {
                    $this->closes[$open] = $token->pos;
                }
            }
        }
        foreach ($errors as $error) {
            $position = $error->getAttributes()['startFilePos'] ?? null;
            if (is_int($position) && $position < $length) {
                $this->errors[] = $position;
            }
        }
        sort($this->errors);
    }

    /**
     * The whole document past its opening tag.
     *
     * @return array{int, int}
     */
    public function fileBody(): array
    {
        return [$this->codeStart, strlen($this->code)];
    }

    /**
     * The inside of the braces that open right after a node's header, to the
     * end of the document if they never close, or null when the header is
     * not followed by a brace. A function's header may end in parameters or
     * a return type its node does not hold.
     *
     * @return ?array{int, int}
     */
    public function bracedBody(int $headerEnd, bool $isFunction): ?array
    {
        $between = $isFunction ? self::AFTER_FUNCTION_HEADER : self::AFTER_HEADER;
        $next = $this->firstCodeAfter($headerEnd);
        while ($next !== null && $next < count($this->tokens) && $this->tokens[$next]->is($between)) {
            $next++;
        }
        $brace = $next === null ? null : $this->tokens[$next] ?? null;
        if ($brace === null || !$brace->is('{')) {
            return null;
        }
        $open = $this->positions[$next];
        return [$open + 1, $this->closes[$open] ?? strlen($this->code)];
    }

    /**
     * The body of a `namespace Name;` declaration: from its `;` to where the
     * next top-level statement starts.
     *
     * @return ?array{int, int}
     */
    public function statementBody(int $headerEnd, int $end): ?array
    {
        $next = $this->firstCodeAfter($headerEnd);
        if ($next === null || !$this->tokens[$next]->is(';')) {
            return null;
        }
        return [$this->positions[$next] + 1, min($end, strlen($this->code))];
    }

    /**
     * The parts of a region that hold code, split where the parser reported
     * an error.
     *
     * @return list<array{int, int}>
     */
    public function piecesOf(int $start, int $end): array
    {
        $end = min($end, strlen($this->code));
        $cuts = [$start];
        foreach ($this->errors as $error) {
            if ($error > $start && $error < $end && !$this->continuesCode($error)) {
                $cuts[] = $error;
            }
        }
        $cuts[] = $end;
        $pieces = [];
        for ($i = 0; $i < count($cuts) - 1; $i++) {
            if ($this->holdsCode($cuts[$i], $cuts[$i + 1])) {
                $pieces[] = [$cuts[$i], $cuts[$i + 1]];
            }
        }
        return $pieces;
    }

    public function text(int $start, int $end): string
    {
        return substr($this->code, $start, $end - $start);
    }

    public function lineOf(int $position): int
    {
        return 1 + substr_count($this->code, "\n", 0, $position);
    }

    /**
     * Whether a region holds code that can read as something: closing
     * brackets alone cannot.
     */
    private function holdsCode(int $start, int $end): bool
    {
        for ($next = $this->firstCodeAfter($start - 1); $next !== null && $this->positions[$next] < $end; $next++) {
            if (!$this->tokens[$next]->is(['}', ')', ']'])) {
                return true;
            }
            if ($next + 1 >= count($this->positions)) {
                return false;
            }
        }
        return false;
    }

    /**
     * Whether code starts right at a position.
     */
    public function codeStartsAt(int $position): bool
    {
        $at = $this->firstCodeAfter($position - 1);
        return $at !== null && $this->positions[$at] === $position;
    }

    /**
     * Whether an error is at a token written right against the one before it,
     * which continues what that token is part of.
     */
    private function continuesCode(int $error): bool
    {
        $previous = $this->tokens[($this->firstCodeAfter($error - 1) ?? 0) - 1] ?? null;
        return $this->codeStartsAt($error)
            && $previous !== null
            && $previous->pos + strlen($previous->text) === $error;
    }

    /**
     * The index of the first code token that starts after a position.
     */
    private function firstCodeAfter(int $position): ?int
    {
        $low = 0;
        $high = count($this->positions);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->positions[$middle] <= $position) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        return $low < count($this->positions) ? $low : null;
    }
}
