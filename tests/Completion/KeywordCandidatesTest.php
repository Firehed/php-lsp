<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\KeywordGroup;
use Firehed\PhpLsp\Document\TextDocument;
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
        yield 'class body' => [KeywordGroup::ClassBody, 'class Foo { ', [
            'public', 'private', 'protected', 'static', 'final', 'abstract', 'readonly', 'const', 'function', 'use',
        ]];
        yield 'class body with a prefix' => [
            KeywordGroup::ClassBody,
            'class Foo { p',
            ['public', 'private', 'protected'],
        ];
        yield 'after visibility' => [
            KeywordGroup::AfterVisibility,
            'public ',
            ['function', 'static', 'readonly', 'const'],
        ];
        yield 'after visibility with a prefix' => [KeywordGroup::AfterVisibility, 'public f', ['function']];
        yield 'expression' => [KeywordGroup::Expression, 'foo(', [
            'new', 'clone', 'yield', 'match', 'fn', 'isset', 'empty', 'list', 'true', 'false', 'null',
        ]];
        yield 'expression with a prefix' => [KeywordGroup::Expression, 'foo(cl', ['clone']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('groupCases')]
    public function testGroupOffersItsKeywordsMatchingThePrefix(
        KeywordGroup $group,
        string $line,
        array $expected,
    ): void {
        $doc = new TextDocument('file:///t.php', 'php', 0, "<?php\n{$line}");
        $request = new CompletionRequest($doc, 1, strlen($line));

        $items = (new KeywordCandidates())->find($request, $group);

        self::assertNotNull($items, 'a keyword position offers keywords');
        self::assertSame(
            $expected,
            array_column($items, 'label'),
            'exactly the keywords valid in the position that start with what was typed',
        );
    }

    public function testExpressionGroupReturnsNullWhenPrefixIsVariable(): void
    {
        // The Expression group reads its prefix from callExpressionPrefix, which
        // returns null when the cursor sits on a variable. The composite guards
        // against this before dispatching, but the class's own contract still
        // promises null-when-not-applicable so a direct caller can rely on it.
        $doc = new TextDocument('file:///t.php', 'php', 0, "<?php\nfoo(\$va");
        $request = new CompletionRequest($doc, 1, 6);

        self::assertNull(
            (new KeywordCandidates())->find($request, KeywordGroup::Expression),
            'a variable prefix inside a call does not offer expression keywords',
        );
    }
}
