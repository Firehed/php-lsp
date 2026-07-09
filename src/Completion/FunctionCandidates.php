<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Resolution\CodeResolver;

/**
 * Produces function completion items: user-defined functions declared in the
 * document (read through {@see CodeResolver}, so parser-agnostic) followed by
 * built-in PHP functions.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class FunctionCandidates
{
    private const RESULT_LIMIT = 100;

    public function __construct(
        private readonly CodeResolver $codeResolver,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(string $prefix, TextDocument $document): array
    {
        $items = [];

        foreach ($this->codeResolver->getFileFunctions($document) as $function) {
            if (PrefixMatcher::matches($function->name, $prefix)) {
                $items[] = CompletionItemFactory::forFunction($function);
            }
        }

        foreach (get_defined_functions()['internal'] as $name) {
            if (PrefixMatcher::matches($name, $prefix)) {
                $items[] = CompletionItemFactory::forBuiltinFunction($name);
            }
        }

        return array_slice($items, 0, self::RESULT_LIMIT);
    }
}
