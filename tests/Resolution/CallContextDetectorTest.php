<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Resolution\CallContextDetector;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use LogicException;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallContextDetector::class)]
final class CallContextDetectorTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function boundaries(): array
    {
        return [
            'typing the first argument' => ['typing_first', 0, 0],
            'after the first argument and a space' => ['after_first_with_space', 0, 0],
            'after a comma' => ['after_comma', 1, 1],
            'after a comma with no space' => ['after_comma_no_space', 1, 1],
            'typing the second argument' => ['typing_second', 1, 1],
        ];
    }

    #[DataProvider('boundaries')]
    public function testCountsOnlyArgumentsClosedByACommaAsFinished(
        string $marker,
        int $activeParameter,
        int $positionalCount,
    ): void {
        $fixture = 'src/Resolution/ArgumentBoundaries.php';
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $syntax = new PhpParserSyntaxSource(new TreeAnnotator());

        $detection = (new CallContextDetector($syntax))->detect(
            $syntax->parse($document),
            $document,
            $this->markerOffset($content, $marker),
        );

        self::assertNotNull($detection, 'the cursor is inside a call');
        self::assertSame($activeParameter, $detection[1], 'the argument being typed is the active parameter');
        self::assertSame($positionalCount, $detection[3], 'only arguments before the cursor\'s argument are filled');
    }

    public function testRejectsACallWithoutItsArgumentSeparators(): void
    {
        $syntax = self::createStub(SyntaxSourceInterface::class);
        $syntax->method('nodeAt')->willReturn(new FuncCall(new Name('f')));

        $this->expectException(LogicException::class);

        (new CallContextDetector($syntax))->detect([], new TextDocument('file:///f.php', 'php', 1, ''), 0);
    }
}
