<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\Completion\WiresCompletionSourceTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * Under a large workspace class-like set matching the same prefix, the
 * built-in `str_*` functions must survive the completion response cap. Class
 * labels sort ahead of lowercase builtin labels in ASCII, so the sortText
 * ranking on both sides is what keeps the family in the cap.
 */
final class CompletionCapFloodTest extends TestCase
{
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

        $map = new ComposerAutoloadMap(classMap: $classmap);
        $production = ProductionSyntaxSource::create();
        $knowledge = KnowledgeStack::forProject($map, $production->source, $production->reader);

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

    public function testBuiltinStringFunctionsSurviveClassLikeFloodAtGlobalScope(): void
    {
        $labels = $this->completeAt("<?php\n\$x = str", line: 1, character: 8);

        self::assertGreaterThanOrEqual(
            5,
            count(self::stringFunctionsIn($labels)),
            'the str_* family must reach the global-scope response under the class-like flood',
        );
        self::assertContains(
            'str_contains',
            $labels,
            'str_contains is a long-stable member of the family; its absence signals a total collapse',
        );
    }

    public function testBuiltinStringFunctionsSurviveClassLikeFloodInNamespacedScope(): void
    {
        $labels = $this->completeAt(
            "<?php\nnamespace App\\Controllers;\n\$x = str",
            line: 2,
            character: 8,
        );

        self::assertGreaterThanOrEqual(
            5,
            count(self::stringFunctionsIn($labels)),
            'the str_* family must reach the response even from an unrelated namespace',
        );
        self::assertContains('str_contains', $labels);
    }

    public function testBuiltinStringFunctionsSurviveClassLikeFloodInsideCallContext(): void
    {
        $labels = $this->completeAt(
            "<?php\nfunction consume(callable \$c): void {}\nconsume(str",
            line: 2,
            character: 11,
        );

        self::assertGreaterThanOrEqual(
            5,
            count(self::stringFunctionsIn($labels)),
            'the str_* family must reach the call-context response under the flood',
        );
        self::assertContains('str_contains', $labels);
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

    /**
     * @return list<string>
     */
    private function completeAt(string $probeSource, int $line, int $character): array
    {
        $this->openDocument(self::PROBE_URI, $probeSource);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => self::PROBE_URI],
                'position' => ['line' => $line, 'character' => $character],
            ],
        ]);
        $result = $this->handler->handle($request);
        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);

        return array_column($result['items'], 'label');
    }

    private function openDocument(string $uri, string $code): void
    {
        $this->syncHandler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => $uri,
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $code,
                ],
            ],
        ]));
    }
}
