<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\CompositeSymbolLocator;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A name may be reachable by arithmetic on the autoload maps or only through the
 * derived `autoload.files` index, so the two routes are chained rather than merged.
 * These prove the chain takes the first answer and falls through when a route
 * cannot answer.
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
