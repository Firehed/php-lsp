<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RFC 1 §4.11: Text-derived fallback logic MUST agree with the syntax-tree path
 * on input that parses; that agreement MUST be held by test, not review.
 *
 * These tests verify that on parseable code, the AST-based resolution and the
 * text-based fallback produce identical results for every positional question
 * both paths answer.
 *
 * Known divergences are marked skipped with their owning slice; each skip is
 * removed when that slice lands and the paths agree.
 */
#[CoversNothing]
final class AstTextAgreementTest extends TestCase
{
    use LoadsFixturesTrait;

    private ParserService $parser;
    private MemberResolver $memberResolver;
    private TextFallbackHelper $textFallback;

    protected function setUp(): void
    {
        $this->parser = new ParserService();

        $knowledge = KnowledgeStack::forProject(
            new ComposerAutoloadMap(),
            __DIR__ . '/../Fixtures/vendor',
            $this->parser,
        );
        $this->memberResolver = new MemberResolver($knowledge->source);
        $this->textFallback = new TextFallbackHelper($this->memberResolver);
    }

    /**
     * The enclosing class-like at a position: for classes, both paths agree.
     * Traits, interfaces, and enums are a known divergence (node-locator slice).
     */
    #[DataProvider('enclosingClassAgreementFixtures')]
    public function testEnclosingClassAgreement(string $fixture, int $line, ?string $expected): void
    {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);

        $astResult = ScopeFinder::findClassAtLine($ast, $line);
        $textResult = $this->textFallback->findEnclosingClassFromContent($content, $line);

        if ($expected === null) {
            self::assertNull($astResult, 'AST path: no enclosing class expected');
            self::assertNull($textResult, 'Text path must agree: no enclosing class');
        } else {
            self::assertNotNull($astResult, 'AST path: enclosing class expected');
            self::assertNotNull($textResult, 'Text path must agree: enclosing class expected');
            self::assertSame(
                $astResult->namespacedName?->toString() ?? $astResult->name?->name,
                $textResult,
                'AST and text paths must agree on the enclosing class name',
            );
        }
    }

    /**
     * @return array<string, array{string, int, ?string}>
     */
    public static function enclosingClassAgreementFixtures(): array
    {
        return [
            'inside class method' => ['src/Domain/User.php', 50, 'Fixtures\Domain\User'],
            'outside any class' => ['src/Domain/User.php', 3, null],
        ];
    }

    /**
     * Known divergence: ScopeFinder::findClassAtLine returns only Stmt\Class_,
     * not traits/interfaces/enums. The text fallback handles all four.
     * Owner: node-locator slice (Step 4).
     */
    #[DataProvider('enclosingClassDivergenceFixtures')]
    public function testEnclosingClassDivergence(string $fixture, int $line, string $expected): void
    {
        $this->markTestSkipped(
            'Known divergence (node-locator): findClassAtLine handles only Class_, '
            . 'text fallback handles all class-likes. See RFC 1 §4.11, Plan 0002 S4.2/S4.4.',
        );
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function enclosingClassDivergenceFixtures(): array
    {
        return [
            'inside trait method' => ['src/Traits/HasTimestamps.php', 15, 'Fixtures\Traits\HasTimestamps'],
            'inside interface' => ['src/Domain/Entity.php', 10, 'Fixtures\Domain\Entity'],
            'inside enum' => ['src/Enum/Status.php', 15, 'Fixtures\Enum\Status'],
        ];
    }
}
