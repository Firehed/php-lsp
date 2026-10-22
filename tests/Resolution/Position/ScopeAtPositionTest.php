<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Resolution\Position\ScopeAtPosition;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopeAtPosition::class)]
final class ScopeAtPositionTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function boundBefore(): iterable
    {
        yield 'in a function' => ['SignatureHelp.php', 'assigned_var', ['user']];
        yield 'in a file' => ['TopLevel/global_scope_completion.php', 'global_member_access', [
            'currentUser',
            'loginCount',
        ]];
        yield 'in a semicolon namespace' => [
            'TopLevel/global_scope_completion_ns.php',
            'global_member_access_ns',
            ['currentUser'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('boundBefore')]
    public function testTheVariablesInScopeAreThoseBoundBeforeThePosition(
        string $fixture,
        string $marker,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->variableNamesAt($fixture, $marker),
            'the assignments before the position in its innermost scope are in scope',
        );
    }

    public function testAReassignedVariableIsInScopeOnce(): void
    {
        $names = $this->variableNamesAt('src/Completion/Variables.php', 'reassigned_prefix');

        self::assertSame(1, count(array_keys($names, 'counter', true)), 'a variable assigned twice is one variable');
    }

    public function testThisIsInScopeInAMethod(): void
    {
        self::assertContains(
            'this',
            $this->variableNamesAt('src/Completion/Variables.php', 'this_prefix'),
            'an instance method binds $this',
        );
    }

    public function testTheNameContextCarriesTheImportsInEffect(): void
    {
        $code = $this->loadFixture('SignatureHelp.php');

        self::assertEquals(
            new NameContext('', ['User' => 'Fixtures\Domain\User', 'Priority' => 'Fixtures\Enum\Priority']),
            self::scope()->nameContextAt(
                new TextDocument('file:///SignatureHelp.php', 'php', 1, $code),
                new ByteOffset($this->markerOffset($code, 'first_param')),
            ),
            'a file without a namespace carries its class imports',
        );
    }

    /**
     * @return list<string>
     */
    private function variableNamesAt(string $fixture, string $marker): array
    {
        $code = $this->loadFixture($fixture);
        $variables = self::scope()->variablesAt(
            new TextDocument('file:///' . $fixture, 'php', 1, $code),
            new ByteOffset($this->markerOffset($code, $marker)),
        );
        return array_map(static fn (ResolvedVariable $variable): string => $variable->getName(), $variables);
    }

    private static function scope(): ScopeAtPosition
    {
        return new ScopeAtPosition(
            new PositionLocator(ProductionSyntaxSource::create()->source),
            new ExpressionResolver(
                self::createStub(MemberResolverInterface::class),
                self::createStub(SymbolSourceInterface::class),
                self::createStub(TypeSourceInterface::class),
            ),
        );
    }
}
