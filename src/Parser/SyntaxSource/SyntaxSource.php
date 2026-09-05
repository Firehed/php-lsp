<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node\Stmt;

/**
 * The syntax read seam: a document in, its top-level statements out.
 *
 * One method, one shape. Consumers hold this interface and never an
 * implementation, so a composite over several sources drops in behind them
 * without touching them (RFC 1 §4.11).
 */
interface SyntaxSource
{
    /**
     * @return array<Stmt>
     */
    public function parse(TextDocument $document): array;
}
