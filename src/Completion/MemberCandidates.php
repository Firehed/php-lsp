<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\MemberAccessContext;

/**
 * Produces member completion items after `->`, `?->`, or `::`.
 *
 * Detection and resolution both flow through {@see CodeResolver}, so this source
 * owns the whole member-access case: it returns null when the position is not a
 * member access (letting the caller try other completion kinds) and a list of
 * items — possibly empty — when it is.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class MemberCandidates
{
    public function __construct(
        private readonly CodeResolver $codeResolver,
        private readonly SessionCapabilitiesProvider $capabilities,
    ) {
    }

    /**
     * @return list<CompletionItem>|null Null when the position is not a member access.
     */
    public function find(TextDocument $document, int $line, int $character): ?array
    {
        $context = $this->codeResolver->getMemberAccessContext($document, $line, $character);
        if ($context === null) {
            return null;
        }

        return $this->itemsFor($context, $document);
    }

    /**
     * @return list<CompletionItem>
     */
    private function itemsFor(MemberAccessContext $context, TextDocument $document): array
    {
        $members = $this->codeResolver->getAccessibleMembers(
            $document,
            $context->type,
            $context->minVisibility,
            $context->memberFilter,
        );

        $snippetSupport = $this->capabilities->getSessionCapabilities()->snippetSupport;

        $items = [];
        foreach ($members as $member) {
            if ($context->accepts($member) && PrefixMatcher::matches($member->getName()->name, $context->prefix)) {
                $items[] = CompletionItemFactory::forResolvedMember($member, $snippetSupport);
            }
        }

        if ($context->offersClassConstant && PrefixMatcher::matches('class', $context->prefix)) {
            $items[] = CompletionItemFactory::forClassConstant();
        }

        return $items;
    }
}
