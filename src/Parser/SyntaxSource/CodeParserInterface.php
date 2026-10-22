<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

/**
 * Reads PHP code into a tree, recovering what the parser can on its own.
 * Every call and attribute in the tree carries its
 * {@see \Firehed\PhpLsp\Parser\ParsedDocument::ARGUMENT_SEPARATORS}.
 * The tree is empty when the parser could not read the code.
 */
interface CodeParserInterface
{
    public function parse(string $code): ParseAttempt;
}
