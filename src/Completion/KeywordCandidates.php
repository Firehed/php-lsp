<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\PrefixMatcher;

/**
 * Produces keyword completion items for a {@see KeywordGroup}.
 *
 * The group is a per-call parameter: the composite passes the intent for the
 * position, and one instance serves every position. The class does not
 * implement {@see CompletionSourceInterface} because its behaviour is chosen
 * by the caller, not by inspection of the request.
 *
 * The Expression group reads the prefix via
 * {@see CompletionClassifier::callExpressionPrefix()} because its position is
 * inside a call; every other group uses the classification prefix.
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
     * @return list<CompletionItem>|null
     */
    public function find(CompletionRequest $request, KeywordGroup $group): ?array
    {
        $prefix = $this->prefixFrom($request, $group);
        if ($prefix === null) {
            return null;
        }

        $items = [];
        foreach ($this->keywordsFor($group) as $keyword) {
            if (PrefixMatcher::matches($keyword, $prefix)) {
                $items[] = CompletionItemFactory::forKeyword($keyword);
            }
        }

        return $items;
    }

    private function prefixFrom(CompletionRequest $request, KeywordGroup $group): ?string
    {
        return match ($group) {
            KeywordGroup::Expression => CompletionClassifier::callExpressionPrefix($request->textBeforeCursor()),
            default => $request->classification()->prefix,
        };
    }

    /**
     * @return list<string>
     */
    private function keywordsFor(KeywordGroup $group): array
    {
        return match ($group) {
            KeywordGroup::All => self::KEYWORDS_ALL,
            KeywordGroup::ClassBody => self::KEYWORDS_CLASS_BODY,
            KeywordGroup::AfterVisibility => self::KEYWORDS_AFTER_VISIBILITY,
            KeywordGroup::Expression => self::KEYWORDS_EXPRESSION,
        };
    }
}
