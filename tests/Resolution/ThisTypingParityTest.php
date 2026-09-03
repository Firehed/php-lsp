<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Both hover on `$this` and completion on `$this->` route through
 * `ExpressionResolver::resolve(Variable('this'))`, which reads the enclosing
 * class through `EnclosingClassResolver`. The two features must agree on the
 * receiver type for the same source input (build-manifest step-31 done clause).
 */
#[CoversClass(SymbolResolver::class)]
final class ThisTypingParityTest extends TestCase
{
    private SymbolResolver $resolver;

    protected function setUp(): void
    {
        $parser = new ParserService();
        $fixturesRoot = __DIR__ . '/../Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $parser,
        );
        $this->resolver = new SymbolResolver(
            $parser,
            $knowledge->source,
            new MemberResolver($knowledge->source),
        );
    }

    public function testHoverAndCompletionAgreeOnThisReceiverType(): void
    {
        $document = $this->loadFixture('src/Resolution/ThisTypingParity.php');

        $hover = $this->resolver->resolveAtPosition($document, line: 12, character: 8);
        self::assertNotNull($hover, 'hover on $this should resolve to a symbol');

        $access = $this->resolver->getMemberAccessContext($document, line: 17, character: 15);
        self::assertNotNull($access, 'completion on $this-> should detect a member-access context');

        self::assertSame(
            $hover->getType()?->format(),
            $access->type->format(),
            'hover on $this and completion on $this-> must resolve to the same enclosing class',
        );
        self::assertSame(
            'Fixtures\Resolution\ThisTypingParity',
            $access->type->format(),
            'the shared helper resolves the enclosing class in the fixture',
        );
        self::assertSame(
            Visibility::Private,
            $access->minVisibility,
            'the vantage class equals the target class, so private members are visible',
        );
    }

    private function loadFixture(string $relative): TextDocument
    {
        $path = __DIR__ . '/../Fixtures/' . $relative;
        $content = file_get_contents($path);
        self::assertIsString($content, "unable to read fixture {$relative}");
        return new TextDocument('file:///' . $relative, 'php', 1, $content);
    }
}
