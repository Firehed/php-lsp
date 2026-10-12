<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;

/**
 * Builds the production-shaped completion composite for tests. The same
 * wiring is duplicated in {@see \Firehed\PhpLsp\Server::forProject}; any
 * structural change to {@see CompositeCompletionSource} breaks compilation
 * at both sites.
 */
trait WiresCompletionSourceTrait
{
    private static function completionSourceFor(
        SymbolSourceInterface $symbolSource,
        CodeResolverInterface $codeResolver,
        SessionCapabilitiesProviderInterface $capabilities,
    ): CompositeCompletionSource {
        $itemFactory = new CompletionItemFactory(new ResolvedSymbolPresenter());
        return new CompositeCompletionSource(
            $codeResolver,
            new SymbolCandidates($symbolSource, $codeResolver, $capabilities, $itemFactory),
            new KeywordCandidates($itemFactory),
            new VariableCandidates($codeResolver, $itemFactory),
            new MemberCandidates($codeResolver, $capabilities, $itemFactory),
            new NamedArgumentCandidates($codeResolver, $itemFactory),
            new BuiltinTypeCandidates($itemFactory),
        );
    }
}
