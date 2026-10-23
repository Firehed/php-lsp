<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Resolution\Position\ScopeAtPositionInterface;

/**
 * Produces variable completion items for the variables in scope at a position.
 *
 * Every variable is offered until a `$` is written, as where variables mix
 * with other items inside a call's argument list.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFormatter
 */
final class VariableCandidates
{
    public function __construct(
        private readonly ScopeAtPositionInterface $scope,
        private readonly CompletionItemFormatter $itemFormatter,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(CompletionRequest $request, string $prefix): array
    {
        $prefix = str_starts_with($prefix, '$') ? substr($prefix, 1) : '';
        $variables = $this->scope->variablesAt($request->document, $request->offset);
        $items = [];
        foreach ($variables as $variable) {
            if (PrefixMatcher::matches($variable->getName(), $prefix)) {
                $items[] = $this->itemFormatter->forVariable($variable);
            }
        }

        return $items;
    }
}
