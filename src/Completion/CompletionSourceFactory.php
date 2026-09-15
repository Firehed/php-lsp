<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;

/**
 * Wires the completion composite for a project. Mirrors the shape of
 * {@see \Firehed\PhpLsp\Knowledge\KnowledgeStack::forProject}: one factory
 * per family, called from the top-level {@see \Firehed\PhpLsp\Server::forProject},
 * so the classes below (NameKind, SymbolSourceInterface, the source
 * implementations) stay inside the family's own layer.
 */
final class CompletionSourceFactory
{
    public static function forProject(
        SymbolSourceInterface $symbolSource,
        CodeResolverInterface $codeResolver,
        SessionCapabilitiesProviderInterface $capabilities,
    ): CompositeCompletionSource {
        $classes = static fn(ClassCandidateFilter $filter): SymbolCandidates => new SymbolCandidates(
            $symbolSource,
            $codeResolver,
            $capabilities,
            [NameKind::ClassLike],
            $filter,
        );

        return new CompositeCompletionSource(
            $codeResolver,
            $classes(ClassCandidateFilter::Instantiable),
            $classes(ClassCandidateFilter::TypeHint),
            $classes(ClassCandidateFilter::Interface_),
            $classes(ClassCandidateFilter::ExtendableClass),
            $classes(ClassCandidateFilter::Throwable),
            $classes(ClassCandidateFilter::Attribute),
            $classes(ClassCandidateFilter::Trait_),
            $classes(ClassCandidateFilter::Any),
            new SymbolCandidates(
                $symbolSource,
                $codeResolver,
                $capabilities,
                NameKind::cases(),
                ClassCandidateFilter::Any,
            ),
            new KeywordCandidates(KeywordGroup::All),
            new KeywordCandidates(KeywordGroup::ClassBody),
            new KeywordCandidates(KeywordGroup::AfterVisibility),
            new KeywordCandidates(KeywordGroup::Expression),
            new VariableCandidates($codeResolver),
            new MemberCandidates($codeResolver, $capabilities),
            new NamedArgumentCandidates($codeResolver),
            new BuiltinTypeCandidates(TypeHintContext::Property),
            new BuiltinTypeCandidates(TypeHintContext::Parameter),
            new BuiltinTypeCandidates(TypeHintContext::ReturnType),
        );
    }
}
