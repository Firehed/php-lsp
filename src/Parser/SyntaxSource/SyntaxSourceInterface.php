<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;

/**
 * The syntax tree of a document, paired with that document. The tree is empty
 * when nothing in the document could be read.
 */
interface SyntaxSourceInterface
{
    public function parse(TextDocument $document): ParsedDocument;
}
