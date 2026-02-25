<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParserService::class)]
class ParserServiceTest extends TestCase
{
    public function testParseValidPhp(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            '<?php function foo() {}',
        );

        $result = $parser->parse($doc);

        self::assertNotNull($result);
        self::assertCount(1, $result);
        self::assertInstanceOf(Function_::class, $result[0]);
    }

    public function testParseClass(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            '<?php class MyClass { public function bar() {} }',
        );

        $result = $parser->parse($doc);

        self::assertNotNull($result);
        self::assertCount(1, $result);
        self::assertInstanceOf(Class_::class, $result[0]);
    }

    public function testParseInvalidPhpReturnsNull(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            '<?php function foo( { }', // syntax error
        );

        $result = $parser->parse($doc);

        self::assertNull($result);
    }

    public function testParseEmptyFile(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            '',
        );

        $result = $parser->parse($doc);

        self::assertNotNull($result);
        self::assertCount(0, $result);
    }
}
