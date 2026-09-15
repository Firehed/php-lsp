<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\KeywordGroup;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Completion\TypeHintContext;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\SymbolResolver;

/**
 * Test-only mirror of the {@see \Firehed\PhpLsp\Server::forProject} wiring for
 * completion. Test suites need a production-shaped CompositeCompletionSource
 * without pulling in the whole Server construction.
 */
trait WiresCompletionSourceTrait
{
    private static function completionSourceFor(
        SymbolSourceInterface $symbolSource,
        SymbolResolver $symbolResolver,
        SessionCapabilitiesProviderInterface $capabilities,
    ): CompositeCompletionSource {
        $classes = static fn(ClassCandidateFilter $filter): SymbolCandidates => new SymbolCandidates(
            $symbolSource,
            $symbolResolver,
            $capabilities,
            [NameKind::ClassLike],
            $filter,
        );

        return new CompositeCompletionSource(
            $symbolResolver,
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
                $symbolResolver,
                $capabilities,
                NameKind::cases(),
                ClassCandidateFilter::Any,
            ),
            new KeywordCandidates(KeywordGroup::All),
            new KeywordCandidates(KeywordGroup::ClassBody),
            new KeywordCandidates(KeywordGroup::AfterVisibility),
            new KeywordCandidates(KeywordGroup::Expression),
            new VariableCandidates($symbolResolver),
            new MemberCandidates($symbolResolver, $capabilities),
            new NamedArgumentCandidates($symbolResolver),
            new BuiltinTypeCandidates(TypeHintContext::Property),
            new BuiltinTypeCandidates(TypeHintContext::Parameter),
            new BuiltinTypeCandidates(TypeHintContext::ReturnType),
        );
    }
}
