<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\BuildsKnowledgeStackTrait;
use Firehed\PhpLsp\Tests\Completion\WiresCompletionSourceTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * Completion must not autoload user code — no backend, no filter, no code
 * path. The workspace's PSR-4 layout in `tests/Fixtures/AutoloadTrap/`
 * includes a file whose declared namespace differs from its PSR-4 candidate,
 * so the walker mints `Trap\RealTrap` under prefix `Trap\` while the file
 * declares `Elsewhere\RealTrap`; downstream lookup on that FQN falls through
 * every backend and reaches the reflection probe.
 */
final class CompletionAutoloadIsolationTest extends TestCase
{
    use BuildsKnowledgeStackTrait;
    use OpensDocumentsTrait;
    use WiresCompletionSourceTrait;

    /** @var callable(string): void */
    private $tracker;

    /** @var list<string> */
    private array $autoloaded = [];

    private DocumentManager $documents;
    private CompletionHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $trapRoot = dirname(__DIR__) . '/Fixtures/AutoloadTrap';

        $map = new ComposerAutoloadMap(psr4: ['Trap\\' => [$trapRoot]]);
        $production = ProductionSyntaxSource::create();
        $knowledge = $this->knowledgeStackForMap($map, $production);

        $this->documents = new DocumentManager();
        $memberResolver = new MemberResolver($knowledge->source);
        $typeSource = new NativeTypeSource($knowledge->source, $memberResolver);
        $symbolResolver = new SymbolResolver(
            $production->source,
            $knowledge->source,
            $memberResolver,
            $typeSource,
        );

        $capabilities = self::createStub(SessionCapabilitiesProviderInterface::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(snippetSupport: false));

        $this->handler = new CompletionHandler(
            $this->documents,
            self::completionSourceFor($knowledge->source, $symbolResolver, $capabilities),
        );
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink, $knowledge->invalidator);

        // Only `Trap\` probes are significant: no other registered autoloader
        // knows the prefix, so anything the tracker sees came from the LSP.
        $this->autoloaded = [];
        $this->tracker = function (string $class): void {
            if (str_starts_with($class, 'Trap\\')) {
                $this->autoloaded[] = $class;
            }
        };
        spl_autoload_register($this->tracker, prepend: true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->tracker);
    }

    public function testCompletionDoesNotAutoloadBogusPsr4Candidates(): void
    {
        $source = $this->loadFixture('src/Completion/AutoloadTrapEditor.php');
        $uri = 'file:///editor.php';
        $this->openDocument($uri, $source);

        $cursor = $this->locateCursor($source, 'type_prefix');
        $result = $this->handler->handle($this->completionRequestAt([
            'uri' => $uri,
            'line' => $cursor['line'],
            'character' => $cursor['character'],
        ]));

        self::assertIsArray($result, 'the handler must answer, not crash the process');
        self::assertContains(
            'RealType',
            array_column($result['items'], 'label'),
            'the imported class must reach the response',
        );
        self::assertSame(
            [],
            $this->autoloaded,
            'the bogus PSR-4 candidate must not reach an autoloading probe',
        );
    }
}
