<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Resolution\CallContextDetector;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallContextDetector::class)]
final class CallContextDetectorTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function argumentSlots(): array
    {
        return [
            'a positional argument' => ['positional', false],
            'the value of a named argument' => ['named_value', true],
            'the start of the argument after a named one' => ['next_argument', false],
        ];
    }

    #[DataProvider('argumentSlots')]
    public function testReportsWhetherTheCursorIsInANamedArgumentsValue(string $marker, bool $expected): void
    {
        $fixture = 'src/Completion/ArgumentSlots.php';
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $syntax = new PhpParserSyntaxSource(new TreeAnnotator());

        $detection = (new CallContextDetector($syntax))->detect(
            $syntax->parse($document),
            $document,
            $this->markerOffset($content, $marker),
        );

        self::assertNotNull($detection, 'the cursor is inside a call');
        self::assertSame($expected, $detection[4], 'whether a named argument\'s value is being typed');
    }
}
