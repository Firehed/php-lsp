<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSinkInterface;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolCandidates::class)]
final class SymbolCandidatesTest extends TestCase
{
    private string $fixturesRoot;
    private SymbolSourceInterface $symbolSource;
    private SymbolSinkInterface $sink;
    private SymbolResolver $symbolResolver;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__) . '/Fixtures';

        $production = ProductionSyntaxSource::create();
        $parser = $production->source;
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot),
            $this->fixturesRoot . '/vendor',
            $parser,
            $production->reader,
        );

        $this->symbolSource = $knowledge->source;
        $this->sink = $knowledge->sink;

        $memberResolver = new MemberResolver($knowledge->source);
        $typeSource = new NativeTypeSource($knowledge->source, $memberResolver);
        $this->symbolResolver = new SymbolResolver(
            $parser,
            $knowledge->source,
            $memberResolver,
            $typeSource,
        );
    }

    public function testShadowedFunctionInCurrentNamespaceIsNotOffered(): void
    {
        $this->openFixture('src/Completion/FunctionCompletion.php');
        $this->openFixture('src/Completion/ShadowedImport.php');

        $request = $this->probe(
            "<?php\nnamespace Fixtures\\Completion;\nuse function str_contains as calculateSum;\ncalc",
        );
        $items = $this->candidates()
            ->find($request, [NameKind::Function_], ClassCandidateFilter::Any);

        $labels = array_column($items, 'label');
        self::assertContains(
            'calculateProduct',
            $labels,
            'an unshadowed function in the same namespace must still be offered',
        );

        $calcSumItems = array_filter(
            $items,
            static fn(array $item): bool => $item['label'] === 'calculateSum',
        );
        self::assertCount(
            1,
            $calcSumItems,
            'exactly one calculateSum item must be offered (the import, not the shadowed namespace function)',
        );
        $calcSumItem = array_values($calcSumItems)[0];
        $detail = $calcSumItem['detail'] ?? '';
        self::assertStringContainsString(
            'str_contains',
            $detail,
            'the offered calculateSum must be the import alias of str_contains, not the namespace function',
        );
    }

    public function testCrossKindFqnCollisionDeduplicates(): void
    {
        $this->openFixture('src/Completion/ShadowedImport.php');

        $request = $this->probe("<?php\nnamespace Fixtures\\Completion;\nShadowedImport");
        $candidates = $this->candidates();
        $any = ClassCandidateFilter::Any;
        $classOnly = $candidates->find($request, [NameKind::ClassLike], $any);
        $functionOnly = $candidates->find($request, [NameKind::Function_], $any);
        $allKinds = $candidates->find($request, NameKind::cases(), $any);

        self::assertCount(1, $classOnly, 'class-only search finds the class');
        self::assertCount(1, $functionOnly, 'function-only search finds the function');

        $allLabels = array_column($allKinds, 'label');
        self::assertCount(
            1,
            $allLabels,
            'cross-kind FQN collision deduplicates: the first kind wins',
        );
    }

    private function candidates(): SymbolCandidates
    {
        $capabilities = self::createStub(SessionCapabilitiesProviderInterface::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities());

        return new SymbolCandidates($this->symbolSource, $this->symbolResolver, $capabilities);
    }

    private function openFixture(string $relativePath): TextDocument
    {
        $path = $this->fixturesRoot . '/' . $relativePath;
        $content = file_get_contents($path);
        self::assertNotFalse($content, "fixture should be readable: {$relativePath}");

        $doc = new TextDocument('file://' . $path, 'php', 0, $content);
        $this->sink->openDocument($doc);
        return $doc;
    }

    /**
     * A cursor document whose text ends at the position under test — the
     * classifier reads the prefix from the tail, and the resolver reads the
     * namespace and imports from the same document.
     */
    private function probe(string $content): CompletionRequest
    {
        $doc = new TextDocument('file:///probe.php', 'php', 0, $content);
        $this->sink->openDocument($doc);
        $lines = explode("\n", $content);
        $lastLine = count($lines) - 1;
        return new CompletionRequest($doc, $lastLine, strlen($lines[$lastLine]));
    }
}
