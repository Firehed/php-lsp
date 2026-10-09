<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;

/**
 * The syntax read seam: a document in, the document and its tree out.
 *
 * Consumers hold this interface and never an implementation, so a composite
 * over several sources drops in behind them without touching them (RFC 1 §4.11).
 */
interface SyntaxSourceInterface
{
    public function parse(TextDocument $document): ParsedDocument;
}
