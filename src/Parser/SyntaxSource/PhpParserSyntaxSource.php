<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\ErrorHandler;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * The {@see SyntaxSource} backed by php-parser: the one class that names
 * {@see \PhpParser\Parser}. Recovers from partial or invalid input through the
 * error-collecting handler, meters every exit path via {@see ParseMetrics}, and
 * hands the resulting tree to {@see TreeAnnotator} so `parent`, `resolvedName`,
 * and `namespacedName` are set the same way every other tree-producing source
 * has them set.
 */
final class PhpParserSyntaxSource implements SyntaxSource
{
    private readonly Parser $parser;

    public function __construct(
        private readonly TreeAnnotator $annotator,
        private readonly ParseMetrics $metrics,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    public function parse(TextDocument $document): array
    {
        $errorHandler = new ErrorHandler\Collecting();
        $startNs = hrtime(true);

        try {
            $ast = $this->parser->parse($document->getContent(), $errorHandler);
            if ($ast === null) {
                return [];
            }
            return $this->annotator->annotate($ast);
        } catch (\PhpParser\Error) {
            return [];
        } finally {
            $this->metrics->record(hrtime(true) - $startNs);
        }
    }
}
