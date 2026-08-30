<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessDetector;
use Firehed\PhpLsp\Resolution\ResolvedTypeOnly;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionResolver::class)]
#[CoversClass(MemberAccessDetector::class)]
#[CoversClass(ResolvedTypeOnly::class)]
class MemberAccessDetectorTest extends TestCase
{
    use LoadsFixturesTrait;

    private MemberAccessDetector $detector;
    private MemberAccessDetector $detectorWithReflection;
    private ParserService $parser;

    protected function setUp(): void
    {
        $this->parser = new ParserService();

        $emptySource = self::createStub(SymbolSource::class);
        $emptySource->method('lookupClassLike')->willReturn(null);
        $emptySource->method('isSubclassOf')->willReturn(false);
        $emptyMemberResolver = new MemberResolver($emptySource);
        $this->detector = new MemberAccessDetector(
            $emptySource,
            $emptyMemberResolver,
            new TextFallbackHelper(),
            $this->parser,
        );

        $fixturesRoot = __DIR__ . '/../Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $this->parser,
        );
        $memberResolver = new MemberResolver($knowledge->source);
        $this->detectorWithReflection = new MemberAccessDetector(
            $knowledge->source,
            $memberResolver,
            new TextFallbackHelper(),
            $this->parser,
        );
    }

    public function testDetectReturnsNullForSelfOutsideClass(): void
    {
        self::assertNull($this->detect('TopLevel/self_outside_class.php', 1, 6));
    }

    public function testDetectReturnsNullForStaticOutsideClass(): void
    {
        self::assertNull($this->detect('TopLevel/static_outside_class.php', 1, 8));
    }

    public function testDetectReturnsNullForParentOutsideClass(): void
    {
        self::assertNull($this->detect('TopLevel/parent_outside_class.php', 1, 8));
    }

    public function testFromTextReturnsNullForStaticOutsideClass(): void
    {
        $content = $this->loadFixture('TopLevel/static_outside_class.php');
        $document = new TextDocument('file:///t.php', 'php', 1, $content);
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast);

        self::assertNull(
            $this->detector->fromText($document, $ast, 1, 8),
            'The text path must decline self::/static:: when the surrounding source has no enclosing class',
        );
    }

    public function testFromTextReturnsNullForParentOutsideClass(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 1, "<?php\nparent::");
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast);

        self::assertNull(
            $this->detector->fromText($document, $ast, 1, 8),
            'The text path must decline parent:: when no enclosing class extends anything',
        );
    }

    public function testDetectReturnsNullForMemberAccessOnPrimitiveParameter(): void
    {
        $document = new TextDocument(
            'file:///t.php',
            'php',
            1,
            "<?php\nfunction test(string \$s): void {\n    \$s->foo;\n}\n",
        );
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast);
        // Cursor sits on `foo`.
        self::assertNull(
            $this->detector->detect($document, $ast, 2, 9),
            'A primitive-typed variable has no members and must yield no context',
        );
    }

    public function testDetectResolvesFullyQualifiedClassName(): void
    {
        $result = $this->detect('TopLevel/fully_qualified.php', 2, 18);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('SomeGlobalClass', $result->type->format());
    }

    public function testDetectResolvesPartiallyQualifiedWithAlias(): void
    {
        $result = $this->detect('TopLevel/aliased_partial.php', 4, 16);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Foo\\Bar\\SubClass', $result->type->format());
    }

    public function testDetectResolvesNestedGroupUse(): void
    {
        $result = $this->detect('TopLevel/nested_group_use.php', 4, 7);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Vendor\\Package\\Sub\\Thing', $result->type->format());
    }

    public function testDetectResolvesSimpleAliasedUse(): void
    {
        $result = $this->detect('TopLevel/simple_aliased.php', 4, 7);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Vendor\\Package\\ClassName', $result->type->format());
    }

    public function testDetectResolvesClassInGlobalNamespace(): void
    {
        $result = $this->detect('TopLevel/no_ast.php', 3, 11);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('SomeClass', $result->type->format());
    }

    public function testDetectSlicesPrefixAtByteColumnPastMultibyte(): void
    {
        $content = $this->loadFixture('TopLevel/multibyte_static.php');
        ['line' => $line, 'character' => $character] = $this->locateCursorUtf16($content, 'multibyte_static');

        $result = $this->detectWith($this->detectorWithReflection, 'TopLevel/multibyte_static.php', $line, $character);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Fixtures\\Domain\\User', $result->type->format());
        self::assertSame(
            'fromArray',
            $result->prefix,
            'slicing the raw wire column would truncate the member prefix (RFC 1 §4.9)',
        );
    }

    public function testDetectResolvesUnimportedClassViaAstNamespace(): void
    {
        $result = $this->detect('TopLevel/namespace_unimported.php', 8, 23);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('App\\Services\\InternalClass', $result->type->format());
    }

    public function testDetectResolvesGlobalNamespaceImport(): void
    {
        $result = $this->detect('TopLevel/global_namespace_use_with_ns.php', 8, 13);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(
            'GlobalClass',
            $result->type->format(),
            'Global namespace import should resolve to GlobalClass, not App\\GlobalClass',
        );
    }

    public function testDetectResolvesAliasedGroupUse(): void
    {
        $result = $this->detect('TopLevel/aliased_group_use.php', 6, 7);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Vendor\\Package\\Something', $result->type->format());
    }

    private function detect(string $fixture, int $line, int $character): ?MemberAccessContext
    {
        return $this->detectWith($this->detector, $fixture, $line, $character);
    }

    private function detectWith(
        MemberAccessDetector $detector,
        string $fixture,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast, 'Parser must return an AST (may be partial)');
        return $detector->detect($document, $ast, $line, $character);
    }
}
