<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\TreeNodeLocator;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Resolution\CallContextDetector;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallContextDetector::class)]
final class CallContextDetectorTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string FIXTURE = 'SignatureHelp.php';

    /**
     * @return array<string, array{string, class-string, int, list<string>, int}>
     */
    public static function calls(): array
    {
        return [
            'first argument' => ['first_param', FuncCall::class, 0, [], 0],
            'second argument' => ['second_param', FuncCall::class, 1, [], 1],
            'named argument' => ['named_arg', FuncCall::class, 1, ['a', 'b'], 0],
            'constructor' => ['constructor', New_::class, 0, [], 0],
            'static call' => ['static_call', StaticCall::class, 0, [], 0],
        ];
    }

    /**
     * @param class-string $callClass
     * @param list<string> $usedNames
     */
    #[DataProvider('calls')]
    public function testDetectsTheEnclosingCall(
        string $marker,
        string $callClass,
        int $activeParameter,
        array $usedNames,
        int $positionalCount,
    ): void {
        [$parsed, $offset] = $this->parsedAtMarker($marker);

        $detection = (new CallContextDetector(new TreeNodeLocator()))->detect($parsed, $offset);

        self::assertNotNull($detection, 'the cursor is inside a call');
        [$call, $active, $used, $positional] = $detection;
        self::assertInstanceOf($callClass, $call, 'the enclosing call is found through the parent links');
        self::assertSame($activeParameter, $active, 'arguments ending before the cursor advance the parameter');
        self::assertSame($usedNames, $used, 'every named argument counts as used');
        self::assertSame($positionalCount, $positional, 'only positional arguments before the cursor fill');
    }

    public function testNoCallEnclosesTheCursor(): void
    {
        [$parsed, $offset] = $this->parsedAtMarker('outside_call');

        self::assertNull(
            (new CallContextDetector(new TreeNodeLocator()))->detect($parsed, $offset),
            'an assignment outside any call has no call context',
        );
    }

    /**
     * @return array{ParsedDocument, int}
     */
    private function parsedAtMarker(string $marker): array
    {
        $content = $this->loadFixture(self::FIXTURE);
        $parsed = (new PhpParserSyntaxSource(new TreeAnnotator()))->parse(
            new TextDocument('file:///' . self::FIXTURE, 'php', 1, $content),
        );

        return [$parsed, $this->markerOffset($content, $marker)];
    }
}
