<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\Container\TypedContainerInterface as TC;

return [
    Capability\CapabilityNegotiator::class,
    Capability\SessionCapabilitiesProviderInterface::class => Capability\CapabilityNegotiator::class,
    Capability\WatchedFilesRegistrar::class,
    Capability\InitializedListenerInterface::class => Capability\WatchedFilesRegistrar::class,

    Client\TransportClientConnection::class,
    Client\ClientConnectionInterface::class => Client\TransportClientConnection::class,

    Completion\BuiltinTypeCandidates::class,
    Completion\CompletionItemFactory::class,
    Completion\CompositeCompletionSource::class,
    Completion\CompletionSourceInterface::class => Completion\CompositeCompletionSource::class,
    Completion\KeywordCandidates::class,
    Completion\MemberCandidates::class,
    Completion\NamedArgumentCandidates::class,
    Completion\SymbolCandidates::class,
    Completion\VariableCandidates::class,

    Document\CompositeDocumentSource::class,
    Document\DocumentManager::class,
    Document\DocumentManagerInterface::class => Document\DocumentManager::class,
    Document\DocumentSourceInterface::class => Document\CompositeDocumentSource::class,
    Document\SourceFileReader::class,

    Parser\TreeAnnotator::class => fn () => new Parser\TreeAnnotator(),
    Parser\SyntaxSource\PhpParserCodeParser::class,
    Parser\SyntaxSource\CodeParserInterface::class => Parser\SyntaxSource\PhpParserCodeParser::class,
    Parser\Recovery\EndOfFileRecovery::class,
    Parser\Recovery\SkeletonRecovery::class,
    Parser\Recovery\GapGraftingRecovery::class,
    Parser\Recovery\CompositeRecovery::class,
    Parser\Recovery\RecoveryInterface::class => Parser\Recovery\CompositeRecovery::class,
    Parser\SyntaxSource\ParsingSyntaxSource::class,
    // The memo decorates the parsing source and is the one instance both the
    // resolvers parse through and the message loop clears.
    Parser\SyntaxSource\MemoizingSyntaxSource::class => fn (TC $c) => new Parser\SyntaxSource\MemoizingSyntaxSource(
        $c->get(Parser\SyntaxSource\ParsingSyntaxSource::class),
    ),
    Parser\SyntaxSource\SyntaxSourceInterface::class => Parser\SyntaxSource\MemoizingSyntaxSource::class,
    Parser\SyntaxSource\MessageScopedInterface::class => Parser\SyntaxSource\MemoizingSyntaxSource::class,

    Parser\NodeLocator\TreeNodeLocator::class,
    Parser\NodeLocator\CursorTextNodeLocator::class,
    Parser\NodeLocator\CompositeNodeLocator::class,
    Parser\NodeLocator\NodeLocatorInterface::class => Parser\NodeLocator\CompositeNodeLocator::class,

    Repository\MemberResolver::class,
    Repository\MemberResolverInterface::class => Repository\MemberResolver::class,

    Resolution\ResolvedSymbolPresenter::class,
    Resolution\ResolvedSymbolPresenterInterface::class => Resolution\ResolvedSymbolPresenter::class,
    Resolution\ExpressionResolver::class,
    Resolution\ExpressionResolverInterface::class => Resolution\ExpressionResolver::class,
    Resolution\Position\PositionLocator::class,
    Resolution\Position\PositionLocatorInterface::class => Resolution\Position\PositionLocator::class,
    Resolution\Position\CallAtPosition::class,
    Resolution\Position\CallAtPositionInterface::class => Resolution\Position\CallAtPosition::class,
    Resolution\Position\ScopeAtPosition::class,
    Resolution\Position\ScopeAtPositionInterface::class => Resolution\Position\ScopeAtPosition::class,
    Resolution\Position\SymbolAtPosition::class,
    Resolution\Position\SymbolAtPositionInterface::class => Resolution\Position\SymbolAtPosition::class,
    Resolution\SymbolResolver::class,
    Resolution\CodeResolverInterface::class => Resolution\SymbolResolver::class,
    Resolution\TypeSource\NativeTypeSource::class,
    Resolution\TypeSource\TypeSourceInterface::class => Resolution\TypeSource\NativeTypeSource::class,
];
