<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Knowledge\SymbolSource;

/**
 * Produces function completion items from the symbol backends: open documents,
 * autoload.files, and PHP built-ins.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class FunctionCandidates
{
    public function __construct(
        private readonly SymbolSource $symbolSource,
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

        foreach ($this->symbolSource->search($prefix, NameKind::Function_) as $symbol) {
            $funcInfo = $this->symbolSource->lookupFunction(
                FunctionName::fromFullyQualified($symbol->fullyQualifiedName),
            );
            if ($funcInfo !== null) {
                $items[] = CompletionItemFactory::forFunction($funcInfo, $snippetSupport);
            } else {
                $items[] = CompletionItemFactory::forBuiltinFunction($symbol->name, $snippetSupport);
            }
        }

        return $items;
    }
}
