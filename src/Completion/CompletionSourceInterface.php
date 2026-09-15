<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * One source of completion items. A source inspects the request, decides
 * whether it applies at that position, and returns either its items or null.
 *
 * Null means "does not apply here; other sources may". An empty list means
 * "applies here but has no items to offer".
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
interface CompletionSourceInterface
{
    /**
     * @return list<CompletionItem>|null
     */
    public function find(CompletionRequest $request): ?array;
}
