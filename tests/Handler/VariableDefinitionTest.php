<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\ResolvedTypeOnly;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\VariableBinding;
use Firehed\PhpLsp\Utility\VariableBindings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Variable go-to-definition per #301.
 *
 * Fixture markers on this suite are end-of-line: `//jtd:<name> <var>` names
 * the cursor and the variable to land on. The `/*|marker*` / pattern cannot
 * be used inside a variable name — it breaks the parser — so a comment marker
 * is used and the helper positions the cursor on the last `$<var>` on the line.
 */
#[CoversClass(DefinitionHandler::class)]
#[CoversClass(ExpressionResolver::class)]
#[CoversClass(ResolvedTypeOnly::class)]
#[CoversClass(ResolvedVariable::class)]
#[CoversClass(Scope::class)]
#[CoversClass(SymbolResolver::class)]
#[CoversClass(VariableBinding::class)]
#[CoversClass(VariableBindings::class)]
class VariableDefinitionTest extends TestCase
{
    use LoadsFixturesTrait;
    use OpensDocumentsTrait;

    private DefinitionHandler $handler;
    private DocumentManager $documents;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $parser = new ParserService();
        $knowledge = KnowledgeStack::forProject(
            new ComposerAutoloadMap(),
            __DIR__ . '/../Fixtures/vendor',
            $parser,
        );
        $memberResolver = new MemberResolver($knowledge->source);
        $symbolResolver = new SymbolResolver($parser, $knowledge->source, $memberResolver);
        $this->handler = new DefinitionHandler($this->documents, $symbolResolver);
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink);
    }

    /**
     * @param positive-int $expectedLine 1-based line of the binding site
     */
    #[DataProvider('bindingUsageCases')]
    public function testJumpsToNearestBinding(string $marker, int $expectedLine): void
    {
        $cursor = $this->cursorOnVariable('src/Definition/VariableBindings.php', $marker);
        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result, "JTD on {$marker} must return a location");
        self::assertSame(
            $expectedLine - 1,
            $result['range']['start']['line'],
            "JTD on {$marker} must land on line {$expectedLine}",
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: positive-int}>
     */
    public static function bindingUsageCases(): iterable
    {
        yield 'assignment' => ['assignment_usage', 11];
        yield 'parameter' => ['param_usage', 15];
        yield 'foreach value' => ['foreach_value_usage', 22];
        yield 'foreach key' => ['foreach_key_usage', 29];
        yield 'catch var' => ['catch_usage', 38];
        yield 'step-back to earlier assignment' => ['second_x', 46];
        yield 'parameter shadows outer' => ['shadowed_usage', 50];
        yield 'long-closure use clause' => ['use_clause_usage', 58];
        yield 'arrow function falls through to enclosing' => ['arrow_fallthrough', 73];
    }

    public function testLongClosureIsolatesUncapturedName(): void
    {
        $cursor = $this->cursorOnVariable('src/Definition/VariableBindings.php', 'closure_uncaptured');
        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertNull($result, 'A long closure must not resolve an uncaptured name to an outer scope');
    }

    public function testResolvesInGlobalScope(): void
    {
        $cursor = $this->cursorOnVariable('TopLevel/global_scope_variable_jtd.php', 'global_assignment_usage');
        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result, 'JTD on a global-scope variable must return a location');
        self::assertSame(
            6,
            $result['range']['start']['line'],
            'JTD in global scope lands on the assignment line',
        );
    }

    public function testThisIsNotAVariableDefinition(): void
    {
        $cursor = $this->cursorOnVariable('src/Definition/VariableBindings.php', 'this_usage');
        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertNull($result, '$this is implicit; JTD returns no location');
    }

    /**
     * Locate the cursor on the last `$<var>` on the line with the `//jtd:<name> <var>` marker.
     *
     * @return array{uri: string, line: int, character: int}
     */
    private function cursorOnVariable(string $fixturePath, string $markerName): array
    {
        [$uri, $content] = $this->loadAndOpenFixture($fixturePath);
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            $marker = "//jtd:{$markerName} ";
            $markerPos = strpos($line, $marker);
            if ($markerPos === false) {
                continue;
            }
            $varName = trim(substr($line, $markerPos + strlen($marker)));
            $lastDollar = strrpos(substr($line, 0, $markerPos), '$' . $varName);
            self::assertNotFalse(
                $lastDollar,
                "Fixture line for {$markerName} must contain $\${$varName}",
            );
            return [
                'uri' => $uri,
                'line' => $lineNum,
                'character' => $lastDollar + 1,
            ];
        }

        self::fail("Marker //jtd:{$markerName} not found in fixture");
    }
}
