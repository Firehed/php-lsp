<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * Produces keyword completion items for a given {@see KeywordGroup}.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class KeywordCandidates
{
    private const KEYWORDS_ALL = [
        // Control flow
        'if', 'else', 'elseif', 'switch', 'case', 'default',
        'while', 'do', 'for', 'foreach', 'break', 'continue',
        'return', 'throw', 'try', 'catch', 'finally',
        // Declarations
        'function', 'class', 'interface', 'trait', 'enum', 'namespace', 'use',
        'extends', 'implements', 'const', 'public', 'protected', 'private',
        'static', 'final', 'abstract', 'readonly',
        // Operators and other
        'new', 'instanceof', 'clone', 'yield', 'match',
        'echo', 'print', 'include', 'include_once', 'require', 'require_once',
        'global', 'unset', 'isset', 'empty', 'list', 'fn',
    ];

    private const KEYWORDS_CLASS_BODY = [
        'public', 'private', 'protected',
        'static', 'final', 'abstract', 'readonly',
        'const', 'function', 'use',
    ];

    private const KEYWORDS_AFTER_VISIBILITY = ['function', 'static', 'readonly', 'const'];

    private const KEYWORDS_EXPRESSION = [
        'new', 'clone', 'yield', 'match', 'fn',
        'isset', 'empty', 'list',
        'true', 'false', 'null',
    ];

    /**
     * @return list<CompletionItem>
     */
    public function find(string $prefix, KeywordGroup $group): array
    {
        $items = [];
        foreach ($this->keywords($group) as $keyword) {
            if (PrefixMatcher::matches($keyword, $prefix)) {
                $items[] = CompletionItemFactory::forKeyword($keyword);
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function keywords(KeywordGroup $group): array
    {
        return match ($group) {
            KeywordGroup::All => self::KEYWORDS_ALL,
            KeywordGroup::ClassBody => self::KEYWORDS_CLASS_BODY,
            KeywordGroup::AfterVisibility => self::KEYWORDS_AFTER_VISIBILITY,
            KeywordGroup::Expression => self::KEYWORDS_EXPRESSION,
        };
    }
}
