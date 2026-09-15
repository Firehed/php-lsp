<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;

/**
 * Wires the completion composite for a project. Mirrors the shape of
 * {@see \Firehed\PhpLsp\Knowledge\KnowledgeStack::forProject}: one factory
 * per family, called from the top-level {@see \Firehed\PhpLsp\Server::forProject},
 * so the source implementations stay inside the family's own layer.
 *
 * Each source is wired once. The composite passes filter/group/context per
 * call to name the intent for each match arm.
 */
final class CompletionSourceFactory
{
    public static function forProject(
        SymbolSourceInterface $symbolSource,
        CodeResolverInterface $codeResolver,
        SessionCapabilitiesProviderInterface $capabilities,
    ): CompositeCompletionSource {
        return new CompositeCompletionSource(
            $codeResolver,
            new SymbolCandidates($symbolSource, $codeResolver, $capabilities),
            new KeywordCandidates(),
            new VariableCandidates($codeResolver),
            new MemberCandidates($codeResolver, $capabilities),
            new NamedArgumentCandidates($codeResolver),
            new BuiltinTypeCandidates(),
        );
    }
}
