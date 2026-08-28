<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessDetector;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAccessDetector::class)]
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
            new BasicTypeResolver($emptyMemberResolver, $emptySource->lookupFunction(...)),
            new TextFallbackHelper($emptyMemberResolver),
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
            new BasicTypeResolver($memberResolver, $knowledge->source->lookupFunction(...)),
            new TextFallbackHelper($memberResolver),
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
        $content = $this->loadFixtureContent('TopLevel/multibyte_static.php');
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
        $content = $this->loadFixtureContent($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast, 'Parser must return an AST (may be partial)');
        return $detector->detect($document, $ast, $line, $character);
    }

    private function loadFixtureContent(string $relativePath): string
    {
        $fullPath = __DIR__ . '/../Fixtures/' . $relativePath;
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to load fixture: $fullPath");
        }
        return $content;
    }
}
