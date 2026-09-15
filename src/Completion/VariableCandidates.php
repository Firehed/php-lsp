<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;

/**
 * Produces variable completion items for the variables in scope at a position.
 *
 * The prefix is read from `$<word>` at the cursor. When there is no `$`, the
 * prefix is empty and every in-scope variable is offered — the composite uses
 * this shape for positions where variables mix with other item kinds, e.g.
 * inside a call argument list.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class VariableCandidates implements CompletionSourceInterface
{
    public function __construct(
        private readonly CodeResolverInterface $codeResolver,
    ) {
    }

    public function find(CompletionRequest $request): array
    {
        $prefix = CompletionClassifier::variablePrefix($request->textBeforeCursor());
        $variables = $this->codeResolver->getVariablesInScope(
            $request->document,
            $request->line,
            $request->character,
        );
        $items = [];
        foreach ($variables as $variable) {
            if (PrefixMatcher::matches($variable->getName(), $prefix)) {
                $items[] = CompletionItemFactory::forVariable(
                    $variable->getName(),
                    $variable->getType()?->format() ?? 'mixed',
                );
            }
        }

        return $items;
    }
}
