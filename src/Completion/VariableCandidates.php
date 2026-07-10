<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Resolution\CodeResolver;

/**
 * Produces variable completion items for the variables in scope at a position.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class VariableCandidates
{
    public function __construct(
        private readonly CodeResolver $codeResolver,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(string $prefix, TextDocument $document, int $line, int $character): array
    {
        $items = [];
        foreach ($this->codeResolver->getVariablesInScope($document, $line, $character) as $variable) {
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
