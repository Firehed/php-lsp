<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\Container\TypedContainerInterface as TC;

return [
    Document\CompositeDocumentSource::class,
    Document\DocumentManager::class,
    Document\DocumentManagerInterface::class => Document\DocumentManager::class,
    Document\DocumentSourceInterface::class => Document\CompositeDocumentSource::class,
    Document\SourceFileReader::class,

    Parser\TreeAnnotator::class => fn () => new Parser\TreeAnnotator(),
    Parser\SyntaxSource\PhpParserSyntaxSource::class,
    Parser\SyntaxSource\SkeletonSyntaxSource::class,
    Parser\SyntaxSource\CompositeSyntaxSource::class,
    // The memo decorates the composite and is the one instance both the
    // resolvers parse through and the message loop clears.
    Parser\SyntaxSource\MemoizingSyntaxSource::class => fn (TC $c) => new Parser\SyntaxSource\MemoizingSyntaxSource(
        $c->get(Parser\SyntaxSource\CompositeSyntaxSource::class),
    ),
    Parser\SyntaxSource\SyntaxSourceInterface::class => Parser\SyntaxSource\MemoizingSyntaxSource::class,
    Parser\SyntaxSource\MessageScopedInterface::class => Parser\SyntaxSource\MemoizingSyntaxSource::class,

    Parser\NodeLocator\TreeNodeLocator::class,
    Parser\NodeLocator\CursorTextNodeLocator::class,
    Parser\NodeLocator\CompositeNodeLocator::class,
    Parser\NodeLocator\NodeLocatorInterface::class => Parser\NodeLocator\CompositeNodeLocator::class,
];
