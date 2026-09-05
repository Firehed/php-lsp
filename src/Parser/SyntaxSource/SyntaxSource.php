<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node\Stmt;

/**
 * The syntax read seam: a document in, its top-level statements out.
 *
 * One method, one shape (build-manifest step-35). Consumers hold this interface
 * so a second producer (step-36's composite over a php-parser source and a
 * skeleton source) drops in without touching them.
 */
interface SyntaxSource
{
    /**
     * @return array<Stmt>
     */
    public function parse(TextDocument $document): array;
}
