<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * Produces built-in type completion items valid in a given type-hint position.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class BuiltinTypeCandidates
{
    /** Types valid in every type-hint position */
    private const COMMON_TYPES = [
        'string', 'int', 'float', 'bool', 'array', 'object',
        'mixed', 'null', 'callable', 'iterable', 'true', 'false',
    ];

    /**
     * @return list<CompletionItem>
     */
    public function find(string $prefix, TypeHintContext $context): array
    {
        $items = [];
        foreach ($this->typesFor($context) as $type) {
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
    private function typesFor(TypeHintContext $context): array
    {
        return match ($context) {
            TypeHintContext::Property => self::COMMON_TYPES,
            TypeHintContext::Parameter => [...self::COMMON_TYPES, 'self', 'parent'],
            TypeHintContext::ReturnType => [...self::COMMON_TYPES, 'void', 'never', 'self', 'static', 'parent'],
        };
    }
}
