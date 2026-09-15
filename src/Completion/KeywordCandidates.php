<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\PrefixMatcher;

/**
 * Produces keyword completion items for one {@see KeywordGroup}.
 *
 * One instance per group is wired: the group is fixed at construction, and the
 * source reads the prefix from the request in the shape that group needs — the
 * Expression group uses {@see CompletionClassifier::callExpressionPrefix()}
 * because its position is inside a call, and every other group uses the
 * classification prefix.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class KeywordCandidates implements CompletionSourceInterface
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

    public function __construct(
        private readonly KeywordGroup $group,
    ) {
    }

    public function find(CompletionRequest $request): ?array
    {
        $prefix = $this->prefixFrom($request);
        if ($prefix === null) {
            return null;
        }

        $items = [];
        foreach ($this->keywords() as $keyword) {
            if (PrefixMatcher::matches($keyword, $prefix)) {
                $items[] = CompletionItemFactory::forKeyword($keyword);
            }
        }

        return $items;
    }

    private function prefixFrom(CompletionRequest $request): ?string
    {
        return match ($this->group) {
            KeywordGroup::Expression => CompletionClassifier::callExpressionPrefix($request->textBeforeCursor()),
            default => $request->classification()->prefix,
        };
    }

    /**
     * @return list<string>
     */
    private function keywords(): array
    {
        return match ($this->group) {
            KeywordGroup::All => self::KEYWORDS_ALL,
            KeywordGroup::ClassBody => self::KEYWORDS_CLASS_BODY,
            KeywordGroup::AfterVisibility => self::KEYWORDS_AFTER_VISIBILITY,
            KeywordGroup::Expression => self::KEYWORDS_EXPRESSION,
        };
    }
}
