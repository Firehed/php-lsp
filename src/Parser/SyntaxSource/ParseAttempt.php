<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\Token;

/**
 * One reading of a document's code on its way to a {@see \Firehed\PhpLsp\Parser\ParsedDocument}:
 * the tree so far, the tokens the code lexes to, and the errors the parser
 * reported. Its tree has argument separators recorded but no parent links or
 * resolved names yet.
 */
final readonly class ParseAttempt
{
    /**
     * @param array<Stmt> $tree
     * @param list<Token> $tokens
     * @param list<Error> $errors
     */
    public function __construct(
        public array $tree,
        public array $tokens,
        public array $errors,
    ) {
    }
}
