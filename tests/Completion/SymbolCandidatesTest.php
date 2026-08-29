<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSink;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolCandidates::class)]
final class SymbolCandidatesTest extends TestCase
{
    private string $fixturesRoot;
    private SymbolSource $symbolSource;
    private SymbolSink $sink;
    private SymbolResolver $symbolResolver;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__) . '/Fixtures';

        $parser = new ParserService();
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot),
            $this->fixturesRoot . '/vendor',
            $parser,
            new SymbolIndex(),
        );

        $this->symbolSource = $knowledge->source;
        $this->sink = $knowledge->sink;

        $memberResolver = new MemberResolver($knowledge->source);
        $this->symbolResolver = new SymbolResolver(
            $parser,
            $knowledge->source,
            $memberResolver,
        );
    }

    public function testShadowedFunctionInCurrentNamespaceIsNotOffered(): void
    {
        $this->openFixture('src/Completion/FunctionCompletion.php');
        $doc = $this->openFixture('src/Completion/ShadowedImport.php');

        $items = $this->candidates()->find(
            'calc',
            $doc,
            7,
            4,
            [NameKind::Function_],
            ClassCandidateFilter::Any,
        );

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
        $doc = $this->openFixture('src/Completion/ShadowedImport.php');

        $classOnly = $this->candidates()->find(
            'ShadowedImport',
            $doc,
            7,
            14,
            [NameKind::ClassLike],
            ClassCandidateFilter::Any,
        );
        $functionOnly = $this->candidates()->find(
            'ShadowedImport',
            $doc,
            7,
            14,
            [NameKind::Function_],
            ClassCandidateFilter::Any,
        );
        $allKinds = $this->candidates()->find(
            'ShadowedImport',
            $doc,
            7,
            14,
            NameKind::cases(),
            ClassCandidateFilter::Any,
        );

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
        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
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
}
