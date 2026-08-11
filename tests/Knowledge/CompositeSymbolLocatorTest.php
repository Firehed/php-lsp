<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\AutoloadFilesLocator;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\CompositeSymbolLocator;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Parser\ParserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A name may be reachable by arithmetic on the autoload maps or only through the
 * derived `autoload.files` index, so the two routes are chained rather than merged.
 * These prove the chain takes the first answer, falls through, and fans invalidation
 * out to the members holding derived state.
 */
#[CoversClass(CompositeSymbolLocator::class)]
final class CompositeSymbolLocatorTest extends TestCase
{
    public function testTheFirstLocatorToAnswerWins(): void
    {
        $locator = new CompositeSymbolLocator([
            self::locatorReturning('/first.php'),
            self::locatorReturning('/second.php'),
        ]);

        self::assertSame(
            '/first.php',
            $locator->locate(self::someName(), NameKind::ClassLike),
            'the earlier locator is the more authoritative route and must win',
        );
    }

    public function testItFallsThroughToALaterLocator(): void
    {
        $locator = new CompositeSymbolLocator([
            self::locatorReturning(null),
            self::locatorReturning('/second.php'),
        ]);

        self::assertSame(
            '/second.php',
            $locator->locate(self::someName(), NameKind::ClassLike),
            'a route that cannot address the name must defer to the next one',
        );
    }

    public function testItReturnsNullWhenNoLocatorAnswers(): void
    {
        $locator = new CompositeSymbolLocator([self::locatorReturning(null), self::locatorReturning(null)]);

        self::assertNull(
            $locator->locate(self::someName(), NameKind::ClassLike),
            'a name no route can address has no declaring file',
        );
    }

    public function testAnEmptyChainAnswersNothing(): void
    {
        self::assertNull(
            (new CompositeSymbolLocator([]))->locate(self::someName(), NameKind::ClassLike),
            'a project with no locators resolves nothing, and is not an error',
        );
    }

    public function testTheLaterLocatorIsNotConsultedOnceOneAnswers(): void
    {
        $second = $this->createMock(SymbolLocator::class);
        $second->expects($this->never())->method('locate');

        $locator = new CompositeSymbolLocator([self::locatorReturning('/first.php'), $second]);

        self::assertSame(
            '/first.php',
            $locator->locate(self::someName(), NameKind::ClassLike),
            'the chain must stop at the first answer rather than costing every route a lookup',
        );
    }

    /**
     * Driven through the real {@see AutoloadFilesLocator} rather than a mock: it is
     * the member that actually holds derived state, so this proves the fan-out
     * reaches the implementation that ships. The leading locator holds none and is
     * not Invalidatable, which is also the production shape — the chain must skip it
     * rather than require every member to implement the interface.
     */
    public function testInvalidateReachesAMemberHoldingDerivedState(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'php-lsp-chain-');
        self::assertNotFalse($path, 'a temp file must be creatable');

        try {
            self::assertNotFalse(
                file_put_contents($path, '<?php function chainedBefore(): void {}'),
                'the temp file must be writable',
            );

            $files = new AutoloadFilesLocator(
                new ComposerAutoloadMap([], [], [], [$path]),
                new ParserService(),
                new DeclarationScanner(),
            );
            $locator = new CompositeSymbolLocator([self::locatorReturning(null), $files]);

            self::assertNotFalse(
                file_put_contents($path, '<?php function chainedAfter(): void {}'),
                'the rewrite must succeed',
            );
            $locator->invalidate(FileUri::fromPath($path));

            self::assertNotNull(
                $locator->locate(QualifiedName::fromFullyQualified('chainedAfter'), NameKind::Function_),
                'invalidation must reach the chained index so the external edit is reflected (RFC 1 §5.2)',
            );
        } finally {
            unlink($path);
        }
    }

    private static function locatorReturning(?string $path): SymbolLocator
    {
        $locator = self::createStub(SymbolLocator::class);
        $locator->method('locate')->willReturn($path);

        return $locator;
    }

    private static function someName(): QualifiedName
    {
        return QualifiedName::fromFullyQualified('Some\Name');
    }
}
