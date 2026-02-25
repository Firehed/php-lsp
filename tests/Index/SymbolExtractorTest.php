<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolExtractor::class)]
class SymbolExtractorTest extends TestCase
{
    private ParserService $parser;
    private SymbolExtractor $extractor;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
        $this->extractor = new SymbolExtractor();
    }

    public function testExtractFunction(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php function myFunc() {}');
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertCount(1, $symbols);
        self::assertSame('myFunc', $symbols[0]->name);
        self::assertSame('myFunc', $symbols[0]->fullyQualifiedName);
        self::assertSame(SymbolKind::Function_, $symbols[0]->kind);
    }

    public function testExtractClass(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php class MyClass {}');
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertCount(1, $symbols);
        self::assertSame('MyClass', $symbols[0]->name);
        self::assertSame('MyClass', $symbols[0]->fullyQualifiedName);
        self::assertSame(SymbolKind::Class_, $symbols[0]->kind);
    }

    public function testExtractNamespacedClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Service;

class UserService {}
PHP;
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertCount(1, $symbols);
        self::assertSame('UserService', $symbols[0]->name);
        self::assertSame('App\\Service\\UserService', $symbols[0]->fullyQualifiedName);
    }

    public function testExtractMethod(): void
    {
        $code = '<?php class MyClass { public function myMethod() {} }';
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertCount(2, $symbols);
        // Class
        self::assertSame('MyClass', $symbols[0]->name);
        // Method
        self::assertSame('myMethod', $symbols[1]->name);
        self::assertSame('MyClass::myMethod', $symbols[1]->fullyQualifiedName);
        self::assertSame(SymbolKind::Method, $symbols[1]->kind);
        self::assertSame('MyClass', $symbols[1]->containerName);
    }

    public function testExtractInterface(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php interface MyInterface {}');
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertCount(1, $symbols);
        self::assertSame('MyInterface', $symbols[0]->name);
        self::assertSame(SymbolKind::Interface_, $symbols[0]->kind);
    }

    public function testExtractTrait(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php trait MyTrait {}');
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertCount(1, $symbols);
        self::assertSame('MyTrait', $symbols[0]->name);
        self::assertSame(SymbolKind::Trait_, $symbols[0]->kind);
    }

    public function testExtractEnum(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php enum Status { case Active; }');
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        // Enum + EnumCase
        self::assertGreaterThanOrEqual(1, count($symbols));
        self::assertSame('Status', $symbols[0]->name);
        self::assertSame(SymbolKind::Enum_, $symbols[0]->kind);
    }
}
