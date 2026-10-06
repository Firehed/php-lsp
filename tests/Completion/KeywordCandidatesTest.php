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
     * @return iterable<string, array{KeywordGroup, string, list<string>, list<string>}>
     */
    public static function groupCases(): iterable
    {
        yield 'statement' => [KeywordGroup::All, 'fore', ['foreach'], ['for']];
        yield 'class body' => [KeywordGroup::ClassBody, 'class Foo { p', ['public', 'private', 'protected'], ['print']];
        yield 'after visibility' => [KeywordGroup::AfterVisibility, 'public f', ['function'], ['fn']];
        yield 'expression' => [KeywordGroup::Expression, 'foo(cl', ['clone'], ['class']];
    }

    /**
     * @param list<string> $offered
     * @param list<string> $withheld
     */
    #[DataProvider('groupCases')]
    public function testGroupOffersItsKeywordsMatchingThePrefix(
        KeywordGroup $group,
        string $line,
        array $offered,
        array $withheld,
    ): void {
        $doc = new TextDocument('file:///t.php', 'php', 0, "<?php\n{$line}");
        $request = new CompletionRequest($doc, 1, strlen($line));

        $items = (new KeywordCandidates())->find($request, $group);

        self::assertNotNull($items, 'a keyword position offers keywords');
        $labels = array_column($items, 'label');
        foreach ($offered as $label) {
            self::assertContains($label, $labels, "{$label} is in the group and matches the prefix");
        }
        foreach ($withheld as $label) {
            self::assertNotContains($label, $labels, "{$label} is outside the group or misses the prefix");
        }
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
