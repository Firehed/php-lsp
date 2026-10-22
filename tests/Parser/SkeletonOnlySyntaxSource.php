<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\Recovery\SkeletonRecovery;
use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Parser\TreeAnnotator;

/**
 * The annotated tree {@see SkeletonRecovery} reads from a document as though
 * nothing else had read it, so tests can compare the skeleton with php-parser
 * on code both can read.
 */
final class SkeletonOnlySyntaxSource implements SyntaxSourceInterface
{
    public function parse(TextDocument $document): ParsedDocument
    {
        $attempt = (new SkeletonRecovery())->recover($document->getContent(), new ParseAttempt([], [], []));
        return new ParsedDocument($document, (new TreeAnnotator())->annotate($attempt->tree));
    }
}
