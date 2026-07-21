<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\ErrorHandler;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Produces the ASTs the rest of the server reads, and memoizes them for the
 * duration of one handled LSP message.
 *
 * The memo is deliberately *not* a standing cache: the Step 0 spike measured a
 * single keystroke costing seven parses of identical content and declined a
 * version-keyed cache in favour of this (0002-execution-plan.md, Section 8.5).
 * Discarding it at the message boundary is what keeps it request-scoped, and the
 * caller responsible for that is the message loop in Server.
 *
 * The key is the content, because the AST is a function of the content alone —
 * parse() reads nothing else off the document. Within one message that makes a
 * stale answer impossible rather than merely unlikely: content that differs at
 * all is a different key, so no invalidation rule has to be got right.
 */
final class ParserService
{
    private ParseMetrics $metrics;
    private Parser $parser;

    /**
     * Content => the AST it produced, for the message being handled.
     *
     * Keyed by content, so array-key: PHP casts an integer-like content string
     * to an int key, and the memo neither notices nor cares.
     *
     * @var array<array-key, array<Stmt>|null>
     */
    private array $scopedParses = [];

    private NodeTraverser $traverser;

    public function __construct()
    {
        $this->metrics = new ParseMetrics();
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->traverser = new NodeTraverser();
        // ParentConnectingVisitor adds 'parent' attribute to all nodes
        $this->traverser->addVisitor(new ParentConnectingVisitor());
        // NameResolver adds 'resolvedName' attribute to Name nodes
        $this->traverser->addVisitor(new NameResolver());
    }

    /**
     * Discards the parses memoized for the message that has finished being
     * handled, so the memo cannot grow into a standing cache.
     */
    public function discardScopedParses(): void
    {
        $this->scopedParses = [];
    }

    public function getMetrics(): ParseMetrics
    {
        return $this->metrics;
    }

    /**
     * @return array<Stmt>|null
     */
    public function parse(TextDocument $document): ?array
    {
        $content = $document->getContent();

        if (!array_key_exists($content, $this->scopedParses)) {
            $this->scopedParses[$content] = $this->parseContent($content);
        }

        return $this->scopedParses[$content];
    }

    /**
     * @return array<Stmt>|null
     */
    private function parseContent(string $content): ?array
    {
        // Use error-collecting handler for partial/incomplete code
        $errorHandler = new ErrorHandler\Collecting();
        $startNs = hrtime(true);

        try {
            $ast = $this->parser->parse($content, $errorHandler);
            if ($ast === null) {
                return [];
            }
            // Resolve names (handles use statements)
            /** @var array<Stmt> */
            return $this->traverser->traverse($ast);
        } catch (\PhpParser\Error) {
            return null;
        } finally {
            $this->metrics->record(hrtime(true) - $startNs);
        }
    }
}
