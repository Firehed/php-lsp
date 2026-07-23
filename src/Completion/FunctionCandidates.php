<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
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
    public function __construct(
        private readonly CodeResolver $codeResolver,
        private readonly SessionCapabilitiesProvider $capabilities,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(string $prefix, TextDocument $document): array
    {
        $snippetSupport = $this->capabilities->getSessionCapabilities()->snippetSupport;

        $items = [];

        foreach ($this->codeResolver->getFileFunctions($document) as $function) {
            if (PrefixMatcher::matches($function->name, $prefix)) {
                $items[] = CompletionItemFactory::forFunction($function, $snippetSupport);
            }
        }

        foreach (get_defined_functions()['internal'] as $name) {
            if (PrefixMatcher::matches($name, $prefix)) {
                $items[] = CompletionItemFactory::forBuiltinFunction($name, $snippetSupport);
            }
        }

        // Capping happens centrally in the handler, after ranking across all
        // sources — not here in arbitrary source order.
        return $items;
    }
}
