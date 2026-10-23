<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Resolution\CallContext;

/**
 * Produces named-argument completion items (`name:`) for a call, skipping
 * parameters already supplied positionally or by name and variadics.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFormatter
 */
final class NamedArgumentCandidates
{
    public function __construct(
        private readonly CompletionItemFormatter $itemFormatter,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(CallContext $callContext, string $prefix): array
    {
        $usedNames = $callContext->usedParameterNames;
        $positionallyFilledCount = $callContext->positionallyFilledCount;

        $items = [];
        foreach ($callContext->callable->getParameters() as $param) {
            // Skip parameters already used as named arguments
            if (in_array($param->name, $usedNames, true)) {
                continue;
            }

            // Skip parameters filled positionally (before the first named arg)
            if ($param->position < $positionallyFilledCount) {
                continue;
            }

            // Skip variadic parameters as named arguments (they use array syntax instead)
            if ($param->isVariadic) {
                continue;
            }

            if (PrefixMatcher::matches($param->name, $prefix)) {
                $items[] = $this->itemFormatter->forNamedArgument($param);
            }
        }

        return $items;
    }
}
