<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedDocument::class)]
final class ParsedDocumentTest extends TestCase
{
    public function testConstruction(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 1, '<?php');
        $tree = [new Nop()];

        $parsed = new ParsedDocument($document, $tree);

        self::assertSame($document, $parsed->document, 'the document is kept as given');
        self::assertSame($tree, $parsed->tree, 'the tree is kept as given');
    }

    /**
     * @return array<string, array{mixed, ?list<int>}>
     */
    public static function separatorAttributes(): array
    {
        return [
            'recorded positions' => [[3, 9], [3, 9]],
            'no commas' => [[], []],
            'not recorded' => [null, null],
            'not a list' => [[1 => 3], null],
            'not positions' => [['3'], null],
        ];
    }

    /**
     * @param ?list<int> $expected
     */
    #[DataProvider('separatorAttributes')]
    public function testArgumentSeparatorsAreReadAsPositions(mixed $attribute, ?array $expected): void
    {
        $call = new FuncCall(new Name('f'));
        if ($attribute !== null) {
            $call->setAttribute(ParsedDocument::ARGUMENT_SEPARATORS, $attribute);
        }

        self::assertSame(
            $expected,
            ParsedDocument::argumentSeparatorsOf($call),
            'a call\'s separators are its recorded positions, or nothing when they are missing or malformed',
        );
    }
}
