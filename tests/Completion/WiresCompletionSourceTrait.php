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
        $items = new CompletionItemFactory();
        return new CompositeCompletionSource(
            $codeResolver,
            new SymbolCandidates($symbolSource, $codeResolver, $capabilities, $items),
            new KeywordCandidates($items),
            new VariableCandidates($codeResolver, $items),
            new MemberCandidates($codeResolver, $capabilities, $items),
            new NamedArgumentCandidates($codeResolver, $items),
            new BuiltinTypeCandidates($items),
        );
    }
}
