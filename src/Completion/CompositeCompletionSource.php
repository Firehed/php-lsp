<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\KeywordGroup;
use Firehed\PhpLsp\Resolution\Position\ExpectedAtPositionInterface;

/**
 * The one completion source the handler consumes. It hands each kind of thing
 * a position admits to the source that offers it, then merges what they offer.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class CompositeCompletionSource implements CompletionSourceInterface
{
    public function __construct(
        private readonly ExpectedAtPositionInterface $expected,
        private readonly MemberCandidates $members,
        private readonly NamedArgumentCandidates $argumentNames,
        private readonly VariableCandidates $variables,
        private readonly KeywordCandidates $keywords,
        private readonly BuiltinTypeCandidates $builtinTypes,
        private readonly SymbolCandidates $names,
    ) {
    }

    public function find(CompletionRequest $request): array
    {
        $expected = $this->expected->expectedAt($request->document, $request->offset);
        $prefix = $expected->prefix;

        return $this->deduplicate(array_merge(
            $expected->members === null ? [] : $this->members->find($expected->members),
            $expected->argumentNames === null ? [] : $this->argumentNames->find($expected->argumentNames, $prefix),
            $expected->variables ? $this->variables->find($request, $prefix) : [],
            $expected->keywords === null ? [] : $this->keywordItems($expected->keywords, $prefix),
            $expected->builtinTypes === null ? [] : $this->builtinTypes->find($expected->builtinTypes, $prefix),
            $expected->names === null ? [] : $this->names->find($request, $expected->names, $prefix),
        ));
    }

    /**
     * @return list<CompletionItem>
     */
    private function keywordItems(KeywordGroup $group, string $prefix): array
    {
        $items = $this->keywords->find($group, $prefix);
        if ($group !== KeywordGroup::Expression) {
            return $items;
        }
        // Expression keywords compete with symbols in an argument; prepend `"`
        // to their sortText so a project symbol beats a keyword on the same prefix.
        return array_map(static fn (array $item): array => ['sortText' => '"' . $item['label']] + $item, $items);
    }

    /**
     * Remove duplicate completions, preferring items that appear earlier.
     *
     * @param list<CompletionItem> $items
     * @return list<CompletionItem>
     */
    private function deduplicate(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = $item['label'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $item;
            }
        }

        return $result;
    }
}
