<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\BuildsKnowledgeStackTrait;
use Firehed\PhpLsp\Tests\Completion\WiresCompletionSourceTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Under a large workspace class-like set matching the same prefix, the
 * built-in `str_*` functions must survive the completion response cap. Class
 * labels sort ahead of lowercase builtin labels in ASCII, so the sortText
 * ranking on both sides is what keeps the family in the cap.
 */
final class CompletionCapFloodTest extends TestCase
{
    use BuildsKnowledgeStackTrait;
    use OpensDocumentsTrait;
    use WiresCompletionSourceTrait;

    // Cap is 100; exceeding it is what makes the ranking observable.
    private const int FLOOD_SIZE = 125;
    private const string FLOOD_NAMESPACE = 'Flood';
    private const string PROBE_URI = 'file:///probe.php';

    private DocumentManager $documents;
    private CompletionHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        // Class-like flood is expressed as a classmap: Any-filter completion
        // never looks these up, so their paths do not need to exist on disk.
        $classmap = [];
        for ($i = 0; $i < self::FLOOD_SIZE; $i++) {
            $classmap[self::FLOOD_NAMESPACE . '\\Str' . sprintf('%03d', $i)] = __FILE__;
        }

        $production = ProductionSyntaxSource::create();
        $knowledge = $this->knowledgeStackForMap(new ComposerAutoloadMap(classMap: $classmap), $production);

        $memberResolver = new MemberResolver($knowledge->source);
        $typeSource = new NativeTypeSource($knowledge->source, $memberResolver);
        $resolver = new SymbolResolver(
            $production->source,
            $knowledge->source,
            $memberResolver,
            $typeSource,
        );

        $capabilities = self::createStub(SessionCapabilitiesProviderInterface::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(snippetSupport: false));

        $this->documents = new DocumentManager();
        $this->handler = new CompletionHandler(
            $this->documents,
            self::completionSourceFor($knowledge->source, $resolver, $capabilities),
        );
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function scopes(): iterable
    {
        yield 'global scope' => ["<?php\n\$x = str", 1, 8];
        yield 'namespaced scope' => ["<?php\nnamespace App\\Controllers;\n\$x = str", 2, 8];
        yield 'call context' => ["<?php\nfunction consume(callable \$c): void {}\nconsume(str", 2, 11];
    }

    #[DataProvider('scopes')]
    public function testBuiltinStringFunctionsSurviveClassLikeFlood(string $source, int $line, int $character): void
    {
        $this->openDocument(self::PROBE_URI, $source);
        $result = $this->handler->handle($this->completionRequestAt([
            'uri' => self::PROBE_URI,
            'line' => $line,
            'character' => $character,
        ]));
        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');

        self::assertGreaterThanOrEqual(
            5,
            count(self::stringFunctionsIn($labels)),
            'the str_* family must reach the response under the class-like flood',
        );
        self::assertContains(
            'str_contains',
            $labels,
            'str_contains is a long-stable member of the family; its absence signals a total collapse',
        );
    }

    /**
     * @param list<string> $labels
     * @return list<string>
     */
    private static function stringFunctionsIn(array $labels): array
    {
        return array_values(array_filter(
            $labels,
            static fn(string $label): bool => str_starts_with($label, 'str_')
                && function_exists($label),
        ));
    }
}
