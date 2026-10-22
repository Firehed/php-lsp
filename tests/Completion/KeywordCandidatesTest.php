<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Domain\KeywordGroup;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeywordCandidates::class)]
final class KeywordCandidatesTest extends TestCase
{
    /**
     * @return iterable<string, array{KeywordGroup, string, list<string>}>
     */
    public static function groupCases(): iterable
    {
        yield 'statement' => [KeywordGroup::All, '', [
            'if', 'else', 'elseif', 'switch', 'case', 'default',
            'while', 'do', 'for', 'foreach', 'break', 'continue',
            'return', 'throw', 'try', 'catch', 'finally',
            'function', 'class', 'interface', 'trait', 'enum', 'namespace', 'use',
            'extends', 'implements', 'const', 'public', 'protected', 'private',
            'static', 'final', 'abstract', 'readonly',
            'new', 'instanceof', 'clone', 'yield', 'match',
            'echo', 'print', 'include', 'include_once', 'require', 'require_once',
            'global', 'unset', 'isset', 'empty', 'list', 'fn',
        ]];
        yield 'statement with a prefix' => [KeywordGroup::All, 'fore', ['foreach']];
        yield 'class body' => [KeywordGroup::ClassBody, '', [
            'public', 'private', 'protected', 'static', 'final', 'abstract', 'readonly', 'const', 'function', 'use',
        ]];
        yield 'class body with a prefix' => [
            KeywordGroup::ClassBody,
            'p',
            ['public', 'private', 'protected'],
        ];
        yield 'after visibility' => [
            KeywordGroup::AfterVisibility,
            '',
            ['function', 'static', 'readonly', 'const'],
        ];
        yield 'after visibility with a prefix' => [KeywordGroup::AfterVisibility, 'f', ['function']];
        yield 'expression' => [KeywordGroup::Expression, '', [
            'new', 'clone', 'yield', 'match', 'fn', 'isset', 'empty', 'list', 'true', 'false', 'null',
        ]];
        yield 'expression with a prefix' => [KeywordGroup::Expression, 'cl', ['clone']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('groupCases')]
    public function testGroupOffersItsKeywordsMatchingThePrefix(
        KeywordGroup $group,
        string $prefix,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            array_column(self::candidates()->find($group, $prefix), 'label'),
            'exactly the keywords valid in the position that start with what was typed',
        );
    }

    private static function candidates(): KeywordCandidates
    {
        return new KeywordCandidates(new CompletionItemFactory(new ResolvedSymbolPresenter()));
    }
}
