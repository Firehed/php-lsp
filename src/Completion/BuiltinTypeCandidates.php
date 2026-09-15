<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\PrefixMatcher;

/**
 * Produces built-in type completion items valid in one type-hint position.
 *
 * The context is fixed at construction; one instance per {@see TypeHintContext}
 * is wired.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class BuiltinTypeCandidates implements CompletionSourceInterface
{
    /** Types valid in every type-hint position */
    private const COMMON_TYPES = [
        'string', 'int', 'float', 'bool', 'array', 'object',
        'mixed', 'null', 'callable', 'iterable', 'true', 'false',
    ];

    public function __construct(
        private readonly TypeHintContext $context,
    ) {
    }

    public function find(CompletionRequest $request): ?array
    {
        $prefix = $request->classification()->prefix;
        $items = [];
        foreach ($this->types() as $type) {
            if (PrefixMatcher::matches($type, $prefix)) {
                $items[] = CompletionItemFactory::forBuiltinType($type);
            }
        }

        return $items;
    }

    /**
     * Context-specific type validity:
     *
     * | Type   | Property | Parameter | Return |
     * |--------|----------|-----------|--------|
     * | void   | No       | No        | Yes    |
     * | never  | No       | No        | Yes    |
     * | self   | No       | Yes       | Yes    |
     * | static | No       | No        | Yes    |
     * | parent | No       | Yes       | Yes    |
     *
     * @return list<string>
     */
    private function types(): array
    {
        return match ($this->context) {
            TypeHintContext::Property => self::COMMON_TYPES,
            TypeHintContext::Parameter => [...self::COMMON_TYPES, 'self', 'parent'],
            TypeHintContext::ReturnType => [...self::COMMON_TYPES, 'void', 'never', 'self', 'static', 'parent'],
        };
    }
}
