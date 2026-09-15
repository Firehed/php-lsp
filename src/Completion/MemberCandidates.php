<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\MemberAccessContext;

/**
 * Produces member completion items after `->`, `?->`, or `::`.
 *
 * Detection and resolution both flow through {@see CodeResolverInterface}, so this source
 * owns the whole member-access case: it returns null when the position is not a
 * member access (letting the composite try other sources) and a list of items —
 * possibly empty — when it is.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class MemberCandidates implements CompletionSourceInterface
{
    public function __construct(
        private readonly CodeResolverInterface $codeResolver,
        private readonly SessionCapabilitiesProviderInterface $capabilities,
    ) {
    }

    public function find(CompletionRequest $request): ?array
    {
        $context = $this->codeResolver->getMemberAccessContext(
            $request->document,
            $request->line,
            $request->character,
        );
        if ($context === null) {
            return null;
        }

        return $this->itemsFor($context, $request->document);
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
