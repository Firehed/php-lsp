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
use Firehed\PhpLsp\Utility\Scope;
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

    #[DataProvider('enclosingClassFixtures')]
    public function testEnclosingClassAgreement(string $fixture, int $line, ?string $expected): void
    {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast, 'Fixture must be parseable for agreement test');

        $offset = $document->offsetAt($line, 0);
        $classLike = Scope::atOffset($ast, $offset)->getEnclosingClassLike();
        $astResult = $classLike !== null ? ScopeFinder::getClassLikeName($classLike) : null;
        $textResult = $this->textFallback->findEnclosingClassFromContent($content, $line);

        self::assertSame($expected, $astResult, 'AST path must match expected');
        self::assertSame($expected, $textResult, 'Text path must agree with AST path');
    }

    /**
     * @return array<string, array{string, int, ?string}>
     */
    public static function enclosingClassFixtures(): array
    {
        return [
            'inside class method' => ['src/Domain/User.php', 50, 'Fixtures\Domain\User'],
            'outside any class' => ['src/Domain/User.php', 3, null],
            'inside trait method' => ['src/Traits/HasTimestamps.php', 15, 'Fixtures\Traits\HasTimestamps'],
            'inside interface' => ['src/Domain/Entity.php', 10, 'Fixtures\Domain\Entity'],
            'inside enum' => ['src/Enum/Status.php', 15, 'Fixtures\Enum\Status'],
        ];
    }
}
