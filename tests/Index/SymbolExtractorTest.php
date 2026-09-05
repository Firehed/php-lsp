<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolExtractor::class)]
class SymbolExtractorTest extends TestCase
{
    use LoadsFixturesTrait;

    private MemoizingSyntaxSource $parser;
    private SymbolExtractor $extractor;

    protected function setUp(): void
    {
        $this->parser = ProductionSyntaxSource::create()->source;
        $this->extractor = new SymbolExtractor();
    }

    public function testExtractFunction(): void
    {
        $code = $this->loadFixture('TypeInference/GlobalFunction.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);

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

        $symbols = $this->extractor->extract($doc, $ast);

        self::assertGreaterThanOrEqual(1, count($symbols));
        self::assertSame('Status', $symbols[0]->name);
        self::assertSame(SymbolKind::Enum_, $symbols[0]->kind);
    }

    public function testExtractNamespacedConstant(): void
    {
        $code = $this->loadFixture('AutoloadFiles/helpers.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);

        $constants = $this->constantsIn($this->extractor->extract($doc, $ast));

        self::assertArrayHasKey(
            'Fixtures\\Helpers\\HELPER_LIMIT',
            $constants,
            'a namespaced const must be indexed under the namespace it is written in',
        );
        $constant = $constants['Fixtures\\Helpers\\HELPER_LIMIT'];
        self::assertSame('HELPER_LIMIT', $constant->name, 'the short name is what a prefix search matches on');
        self::assertSame(
            self::lineContaining($code, 'const HELPER_LIMIT'),
            $constant->location->startLine,
            'the location must come from the declaring node, so go-to-definition lands on it',
        );
    }

    /**
     * The extractor must not grow its own opinion of what declares a constant: the
     * `define()` spellings, the multi-declarator statement and the computed name that
     * {@see DeclarationScanner} already rules on are the same set here. A second rule
     * is how a name came to resolve on hover while being invisible to completion.
     */
    public function testConstantsAgreeWithTheDeclarationScanner(): void
    {
        $code = $this->loadFixture('AutoloadFiles/globals.php');
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);

        $extracted = array_keys($this->constantsIn($this->extractor->extract($doc, $ast)));
        $scanned = array_map(
            fn($declaration) => $declaration->name->fullyQualifiedName(),
            (new DeclarationScanner())->scan($ast)->constants,
        );

        sort($extracted);
        sort($scanned);
        self::assertNotEmpty($scanned, 'the fixture must declare constants, or this asserts nothing');
        self::assertSame($scanned, $extracted, 'the index must report exactly the declarations the scanner finds');
    }

    /**
     * @param list<\Firehed\PhpLsp\Index\Symbol> $symbols
     * @return array<string, \Firehed\PhpLsp\Index\Symbol> FQN -> symbol
     */
    private function constantsIn(array $symbols): array
    {
        $constants = [];
        foreach ($symbols as $symbol) {
            if ($symbol->kind === SymbolKind::Constant) {
                $constants[$symbol->fullyQualifiedName] = $symbol;
            }
        }

        return $constants;
    }

    private static function lineContaining(string $code, string $needle): int
    {
        foreach (explode("\n", $code) as $number => $line) {
            if (str_contains($line, $needle)) {
                return $number;
            }
        }

        self::fail("the fixture no longer contains {$needle}");
    }
}
