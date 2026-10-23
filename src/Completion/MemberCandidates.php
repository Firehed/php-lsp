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
 * @phpstan-import-type CompletionItem from CompletionItemFormatter
 */
final class MemberCandidates
{
    public function __construct(
        private readonly SymbolCapabilitiesInterface $symbols,
        private readonly SessionCapabilitiesProviderInterface $capabilities,
        private readonly CompletionItemFormatter $itemFormatter,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(MemberAccessContext $context): array
    {
        $members = $this->symbols->getAccessibleMembers(
            $context->access->on,
            $context->access->from,
            $context->memberFilter,
            $context->access->static,
        );

        $snippetSupport = $this->capabilities->getSessionCapabilities()->snippetSupport;

        $items = [];
        foreach ($members as $member) {
            if ($context->accepts($member) && PrefixMatcher::matches($member->getName()->name, $context->prefix)) {
                $items[] = $this->itemFormatter->forResolvedMember($member, $snippetSupport);
            }
        }

        if ($context->offersClassConstant && PrefixMatcher::matches('class', $context->prefix)) {
            $items[] = $this->itemFormatter->forClassConstant();
        }

        return $items;
    }
}
