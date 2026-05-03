<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolExtractor::class)]
class SymbolExtractorTest extends TestCase
{
    use LoadsFixturesTrait;

    private ParserService $parser;
    private SymbolExtractor $extractor;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
        $this->extractor = new SymbolExtractor();
    }

    public function testExtractFunction(): void
    {
        $code = $this->loadFixture('TypeInference/GlobalFunction.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);
        $functions = array_filter($symbols, fn($s) => $s->kind === SymbolKind::Function_);

        self::assertNotEmpty($functions);
        $func = reset($functions);
        self::assertNotFalse($func);
        self::assertSame('getGlobalConfig', $func->name);
        self::assertSame('getGlobalConfig', $func->fullyQualifiedName);
    }

    public function testExtractClass(): void
    {
        $code = $this->loadFixture('NoNamespace.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertGreaterThanOrEqual(1, count($symbols));
        self::assertSame('NoNamespaceClass', $symbols[0]->name);
        self::assertSame('NoNamespaceClass', $symbols[0]->fullyQualifiedName);
        self::assertSame(SymbolKind::Class_, $symbols[0]->kind);
    }

    public function testExtractNamespacedClass(): void
    {
        $code = $this->loadFixture('src/Domain/User.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);
        $classes = array_filter($symbols, fn($s) => $s->kind === SymbolKind::Class_);

        self::assertNotEmpty($classes);
        $class = reset($classes);
        self::assertNotFalse($class);
        self::assertSame('User', $class->name);
        self::assertSame('Fixtures\\Domain\\User', $class->fullyQualifiedName);
    }

    public function testExtractMethod(): void
    {
        $code = $this->loadFixture('src/Domain/Entity.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);
        $methods = array_filter($symbols, fn($s) => $s->kind === SymbolKind::Method);

        self::assertNotEmpty($methods);
        $method = reset($methods);
        self::assertNotFalse($method);
        self::assertSame('getId', $method->name);
        self::assertSame('Fixtures\\Domain\\Entity::getId', $method->fullyQualifiedName);
        self::assertSame('Entity', $method->containerName);
    }

    public function testExtractInterface(): void
    {
        $code = $this->loadFixture('src/Domain/Entity.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertGreaterThanOrEqual(1, count($symbols));
        self::assertSame('Entity', $symbols[0]->name);
        self::assertSame(SymbolKind::Interface_, $symbols[0]->kind);
    }

    public function testExtractTrait(): void
    {
        $code = $this->loadFixture('src/Traits/HasTimestamps.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertGreaterThanOrEqual(1, count($symbols));
        self::assertSame('HasTimestamps', $symbols[0]->name);
        self::assertSame(SymbolKind::Trait_, $symbols[0]->kind);
    }

    public function testExtractEnum(): void
    {
        $code = $this->loadFixture('src/Enum/Status.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertGreaterThanOrEqual(1, count($symbols));
        self::assertSame('Status', $symbols[0]->name);
        self::assertSame(SymbolKind::Enum_, $symbols[0]->kind);
    }
}
