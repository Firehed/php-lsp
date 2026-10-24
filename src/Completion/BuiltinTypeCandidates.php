<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\TypeHintContext;

/**
 * Produces built-in type completion items valid in a type-hint position.
 *
 * The context is a per-call parameter: the composite passes the intent for the
 * position, and one instance serves every position. The class does not
 * implement {@see CompletionSourceInterface} because its behaviour is chosen
 * by the caller, not by inspection of the request.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFormatter
 */
final class BuiltinTypeCandidates
{
    public function __construct(
        private readonly CompletionItemFormatter $itemFormatter,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(TypeHintContext $context, string $prefix): array
    {
        $items = [];
        foreach ([...PrimitiveType::cases(), ...LateBindingKeyword::cases()] as $type) {
            if ($type->validIn($context) && PrefixMatcher::matches($type->value, $prefix)) {
                $items[] = $this->itemFormatter->forBuiltinType($type->value);
            }
        }

        return $items;
    }
}
