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

final class ParserService
{
    private ParseMetrics $metrics;
    private Parser $parser;
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

    public function getMetrics(): ParseMetrics
    {
        return $this->metrics;
    }

    /**
     * @return array<Stmt>|null
     */
    public function parse(TextDocument $document): ?array
    {
        // Use error-collecting handler for partial/incomplete code
        $errorHandler = new ErrorHandler\Collecting();
        $startNs = hrtime(true);

        try {
            $ast = $this->parser->parse($document->getContent(), $errorHandler);
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
