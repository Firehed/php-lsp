<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\CompletionSourceFactory;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;

/**
 * Convenience for test cases that build a production-shaped completion
 * composite. The factory is what {@see \Firehed\PhpLsp\Server::forProject}
 * uses; this trait keeps the tests going through the same path.
 */
trait WiresCompletionSourceTrait
{
    private static function completionSourceFor(
        SymbolSourceInterface $symbolSource,
        CodeResolverInterface $codeResolver,
        SessionCapabilitiesProviderInterface $capabilities,
    ): CompositeCompletionSource {
        return CompletionSourceFactory::forProject($symbolSource, $codeResolver, $capabilities);
    }
}
