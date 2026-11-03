<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionItemFormatter;
use Firehed\PhpLsp\Completion\MagicMethodCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MagicMethodCandidates::class)]
final class MagicMethodCandidatesTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string FIXTURE = 'MultiClass/MagicMethods.php';

    /**
     * Every class-like in the fixture declares a method named `__`, with the
     * modifiers its name describes.
     *
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function declarations(): iterable
    {
        yield 'what the class has not declared, in any case' => ['MagicMethodClass', '__', [
            '__call', '__clone', '__debugInfo', '__destruct', '__get', '__invoke', '__isset', '__serialize',
            '__set', '__sleep', '__unserialize', '__unset', '__wakeup',
        ]];
        yield 'what the prefix matches' => ['MagicMethodClass', '__se', ['__serialize', '__set']];
        yield 'a static method' => ['MagicMethodStatic', '__', ['__callStatic', '__set_state']];
        yield 'a private method' => ['MagicMethodPrivate', '__', ['__clone', '__construct', '__destruct']];
        yield 'an enum\'s method' => ['MagicMethodEnum', '__', ['__call', '__invoke']];
        yield 'a method with no visibility written is public' => ['MagicMethodTrait', '__', [
            '__call', '__clone', '__construct', '__debugInfo', '__destruct', '__get', '__invoke', '__isset',
            '__serialize', '__set', '__sleep', '__toString', '__unserialize', '__unset', '__wakeup',
        ]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('declarations')]
    public function testOffersWhatTheDeclarationMayStillBe(string $class, string $prefix, array $expected): void
    {
        self::assertSame(
            $expected,
            $this->labels(new MethodName(self::classLike($class), '__'), $prefix),
            'a magic method the declaration\'s class-like and modifiers allow, not yet declared, matching the prefix',
        );
    }

    public function testAnUnknownClassLikeOffersNothing(): void
    {
        self::assertSame(
            [],
            $this->labels(new MethodName(self::classLike('Unknown'), '__'), '__'),
            'with no class-like, nothing says what the declaration may be',
        );
    }

    public function testAMethodTheClassLikeDoesNotDeclareOffersNothing(): void
    {
        self::assertSame(
            [],
            $this->labels(new MethodName(self::classLike('MagicMethodClass'), 'missing'), '__'),
            'with no declaration, nothing says which modifiers it has',
        );
    }

    /**
     * @return list<string>
     */
    private function labels(MethodName $declared, string $prefix): array
    {
        $symbols = new OpenDocumentBackend(ProductionSyntaxSource::create()->declarations);
        $symbols->openDocument(new TextDocument('file:///' . self::FIXTURE, 'php', 1, $this->loadFixture(self::FIXTURE)));
        $candidates = new MagicMethodCandidates($symbols, new CompletionItemFormatter(new ResolvedSymbolPresenter()));

        return array_column($candidates->find($declared, $prefix), 'label');
    }

    private static function classLike(string $class): ClasslikeName
    {
        return ClasslikeName::fromFullyQualified('Fixtures\MultiClass\\' . $class);
    }
}
