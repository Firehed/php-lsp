<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\CallContextDetector;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
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
    private CallContextDetector $callDetector;

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
        $this->callDetector = new CallContextDetector($this->textFallback);
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

    #[DataProvider('nameContextFixtures')]
    public function testNameContextAgreement(string $fixture, int $line): void
    {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast, 'Fixture must be parseable for agreement test');

        $fromAst = NameContextFactory::fromAst($ast, $line);
        $fromText = NameContextFactory::fromText(explode("\n", $content), $line);

        self::assertSame(
            $fromAst->namespace,
            $fromText->namespace,
            'Namespace must agree between AST and text paths',
        );
        self::assertSame(
            $fromAst->classImports,
            $fromText->classImports,
            'Class imports must agree between AST and text paths',
        );
    }

    /**
     * @param class-string<FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute> $expectedNodeClass
     */
    #[DataProvider('callContextFixtures')]
    public function testCallContextAgreement(
        string $fixture,
        string $marker,
        string $expectedNodeClass,
        int $expectedActiveParam,
    ): void {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast, 'Fixture must be parseable for agreement test');

        $offset = $this->markerOffset($content, $marker);
        $line = $this->lineForOffset($content, $offset);

        $astResult = $this->callDetector->fromAst($ast, $offset);
        $textResult = $this->callDetector->fromText($ast, $offset, $content, $line);

        self::assertNotNull($astResult, 'AST path must detect the call');
        self::assertNotNull($textResult, 'Text path must detect the call');

        self::assertInstanceOf(
            $expectedNodeClass,
            $astResult[0],
            'AST path must find the expected node type',
        );
        self::assertSame(
            $astResult[0]::class,
            $textResult[0]::class,
            'Call node type must agree between AST and text paths',
        );
        self::assertSame(
            $expectedActiveParam,
            $astResult[1],
            'AST active parameter must match expected',
        );
        self::assertSame(
            $astResult[1],
            $textResult[1],
            'Active parameter must agree between AST and text paths',
        );
        self::assertSame(
            $astResult[2],
            $textResult[2],
            'Used parameter names must agree between AST and text paths',
        );
        self::assertSame(
            $astResult[3],
            $textResult[3],
            'Positional count must agree between AST and text paths',
        );
    }

    /**
     * @return array<string, array{string, string, class-string, int}>
     */
    public static function callContextFixtures(): array
    {
        return [
            'function call first arg' => [
                'SignatureHelp.php', 'first_param', FuncCall::class, 0,
            ],
            'function call second arg' => [
                'SignatureHelp.php', 'second_param', FuncCall::class, 1,
            ],
            'constructor' => [
                'SignatureHelp.php', 'constructor', New_::class, 0,
            ],
            'static call' => [
                'SignatureHelp.php', 'static_call', StaticCall::class, 0,
            ],
            'builtin function' => [
                'SignatureHelp.php', 'builtin', FuncCall::class, 0,
            ],
            '$this method call' => [
                'src/Domain/User.php', 'sig_this_call', MethodCall::class, 0,
            ],
            'new expression' => [
                'src/Domain/User.php', 'sig_new', New_::class, 0,
            ],
            'builtin strlen' => [
                'src/Domain/User.php', 'sig_builtin_func', FuncCall::class, 0,
            ],
            'named args' => [
                'SignatureHelp.php', 'named_arg', FuncCall::class, 1,
            ],
            'typed param method call' => [
                'SignatureHelp.php', 'typed_param', MethodCall::class, 0,
            ],
            'nullsafe method call' => [
                'SignatureHelp.php', 'nullsafe_param', NullsafeMethodCall::class, 0,
            ],
        ];
    }

    private function markerOffset(string $content, string $marker): int
    {
        $pos = strpos($content, '/*|' . $marker . '*/');
        self::assertNotFalse($pos, "Marker {$marker} not found");
        return $pos;
    }

    private function lineForOffset(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function nameContextFixtures(): array
    {
        return [
            'simple namespace + imports' => ['src/Domain/User.php', 10],
            'aliased import' => ['src/IncompleteCode/AliasedImports.php', 14],
            'group import' => ['src/IncompleteCode/GroupImports.php', 12],
            'group import with alias' => ['src/IncompleteCode/GroupImports.php', 38],
            'no imports' => ['src/Enum/Status.php', 10],
        ];
    }
}
