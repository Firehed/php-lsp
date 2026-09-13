<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * The {@see SyntaxSourceInterface} backed by php-parser: the one class that names
 * {@see \PhpParser\Parser}. Recovers from partial or invalid input through the
 * error-collecting handler, meters every exit path via {@see ParseMetrics}, and
 * hands the resulting tree to {@see TreeAnnotator} so `parent`, `resolvedName`,
 * and `namespacedName` are set the same way every other tree-producing source
 * has them set.
 */
final class PhpParserSyntaxSource implements SyntaxSourceInterface
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
            $tree = $this->annotator->annotate($ast);
            foreach ($tree as $stmt) {
                $stmt->setAttribute(self::PRODUCER_ATTRIBUTE, true);
            }
            return $tree;
        } catch (\PhpParser\Error) {
            return [];
        } finally {
            $this->metrics->record(hrtime(true) - $startNs);
        }
    }

    /**
     * The composite passes the first non-empty parse's tree to every source's
     * `nodeAt`. When php-parser produced nothing, the tree in hand came from a
     * later source (the skeleton), and this source has no business walking it —
     * the marker set in `parse()` says the tree is ours. When the innermost
     * hit is a bare statement (a class, a method, the namespace) rather than an
     * expression, php-parser has nothing cursor-shaped to offer at this offset;
     * yielding null lets the cursor-text source synthesize one from the source
     * text (build-manifest step-40).
     *
     * @param array<\PhpParser\Node\Stmt> $tree
     */
    public function nodeAt(array $tree, TextDocument $document, int $offset): ?Node
    {
        if ($tree === [] || $tree[0]->getAttribute(self::PRODUCER_ATTRIBUTE) !== true) {
            return null;
        }
        $node = (new NodeAtPosition())->find($tree, $offset);
        if ($node instanceof \PhpParser\Node\Stmt) {
            return null;
        }
        return $node;
    }

    private const string PRODUCER_ATTRIBUTE = 'phpLsp.phpParserSource';
}
