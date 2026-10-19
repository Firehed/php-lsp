<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ArgumentList;
use Firehed\PhpLsp\Parser\ParsedDocument;
use LogicException;
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

    public function testARecordedArgumentListIsReadBack(): void
    {
        $list = new ArgumentList(1, [3, 9]);
        $call = new FuncCall(new Name('f'), attributes: [ParsedDocument::ARGUMENT_LIST => $list]);

        self::assertSame($list, ParsedDocument::argumentListOf($call), 'the recorded argument list is the call\'s');
    }

    public function testACallWrittenWithoutParenthesesHasNoArgumentList(): void
    {
        $call = new FuncCall(new Name('f'), attributes: [ParsedDocument::ARGUMENT_LIST => null]);

        self::assertNull(ParsedDocument::argumentListOf($call), 'null records that there is no argument list');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unrecordedArgumentLists(): array
    {
        return [
            'missing' => [[]],
            'not an argument list' => [[ParsedDocument::ARGUMENT_LIST => [3, 9]]],
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('unrecordedArgumentLists')]
    public function testACallWhoseSourceDidNotRecordItsArgumentListIsRejected(array $attributes): void
    {
        $this->expectException(LogicException::class);

        ParsedDocument::argumentListOf(new FuncCall(new Name('f'), attributes: $attributes));
    }
}
