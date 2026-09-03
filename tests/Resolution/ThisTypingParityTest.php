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
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
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
    use LoadsFixturesTrait;

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
        $relative = 'src/Resolution/ThisTypingParity.php';
        $content = $this->loadFixture($relative);
        $document = new TextDocument('file:///' . $relative, 'php', 1, $content);

        // Hover position: on the `$this` expression inside hoverOnThis(). The
        // marker syntax cannot land inside a variable token, so this position
        // is derived from the fixture — any offset inside `$this` resolves the
        // same symbol.
        $hoverLine = self::lineOf($content, '        $this;');
        $hover = $this->resolver->resolveAtPosition($document, $hoverLine, 8);
        self::assertNotNull($hover, 'hover on $this should resolve to a symbol');

        $completionCursor = $this->locateCursor($content, 'this_member');
        $access = $this->resolver->getMemberAccessContext(
            $document,
            $completionCursor['line'],
            $completionCursor['character'],
        );
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

    private static function lineOf(string $content, string $needle): int
    {
        $offset = strpos($content, $needle);
        assert($offset !== false, "fixture is missing the line \"$needle\"");
        return substr_count(substr($content, 0, $offset), "\n");
    }
}
