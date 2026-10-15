<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parse-states end-to-end script asks the same questions of one scenario
 * in three parse states. Each state is defined by its fixture's parse outcome,
 * not its file name, so a fixture that drifts into another state's outcome
 * would make two of the script's states the same state.
 */
#[CoversNothing]
final class ParseStateFixturesTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function states(): iterable
    {
        yield 'clean' => ['src/ParseHealth/Clean.php', false, true];
        yield 'recovered' => ['src/ParseHealth/Recovered.php', true, true];
        yield 'empty' => ['src/ParseHealth/EmptyParse.php', true, false];
    }

    #[DataProvider('states')]
    public function testFixtureKeepsItsParseOutcome(string $fixture, bool $reportsErrors, bool $yieldsStatements): void
    {
        $errors = new Collecting();
        $statements = (new ParserFactory())
            ->createForNewestSupportedVersion()
            ->parse($this->loadFixture($fixture), $errors) ?? [];

        self::assertSame(
            $reportsErrors,
            $errors->hasErrors(),
            "{$fixture} must " . ($reportsErrors ? 'make the parser report an error' : 'parse without error'),
        );
        self::assertSame(
            $yieldsStatements,
            $statements !== [],
            "{$fixture} must " . ($yieldsStatements ? 'yield statements' : 'yield no statements'),
        );
    }
}
