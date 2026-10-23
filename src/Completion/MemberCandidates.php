<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\SymbolCapabilitiesInterface;

/**
 * Produces completion items for the members a member access reaches.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class MemberCandidates
{
    public function __construct(
        private readonly SymbolCapabilitiesInterface $symbols,
        private readonly SessionCapabilitiesProviderInterface $capabilities,
        private readonly CompletionItemFactory $itemFactory,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(MemberAccessContext $context): array
    {
        $members = $this->symbols->getAccessibleMembers(
            $context->type,
            $context->from,
            $context->memberFilter,
            $context->static,
        );

        $snippetSupport = $this->capabilities->getSessionCapabilities()->snippetSupport;

        $items = [];
        foreach ($members as $member) {
            if ($context->accepts($member) && PrefixMatcher::matches($member->getName()->name, $context->prefix)) {
                $items[] = $this->itemFactory->forResolvedMember($member, $snippetSupport);
            }
        }

        if ($context->offersClassConstant && PrefixMatcher::matches('class', $context->prefix)) {
            $items[] = $this->itemFactory->forClassConstant();
        }

        return $items;
    }
}
