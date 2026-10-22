<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\SyntaxSource\ParsingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Parser\TreeAnnotator;

/**
 * A syntax source that reads code with php-parser alone, for tests that need
 * the tree php-parser recovers without any further recovery.
 */
final class ParserOnlySyntaxSource
{
    public static function create(): ParsingSyntaxSource
    {
        return new ParsingSyntaxSource(new PhpParserCodeParser(), new TreeAnnotator());
    }
}
