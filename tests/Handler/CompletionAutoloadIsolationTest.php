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
use PHPUnit\Framework\TestCase;

/**
 * The LSP reads code; it must never execute it. Completion is the widest read
 * seam — a single keystroke can reach every backend — so an accidental
 * `class_exists($fqn)` (autoload on) or `new ReflectionClass($fqn)` on a user
 * FQN executes arbitrary top-level code from the serviced project: framework
 * bootstrappers, fixture files with intentionally malformed PHP, side-effect
 * initializers. The LSP process crashes silently and the client sees no
 * completion at all.
 *
 * This isolation is a property of the whole stack, not of one backend. It
 * holds on every path a completion can take.
 *
 * The workspace under test is the static fixture at
 * {@see \tests/Fixtures/AutoloadTrap/}: one file whose namespace matches its
 * PSR-4 candidate (`Trap\RealType`), one whose does not (walker mints
 * `Trap\RealTrap`; the file actually declares `Elsewhere\RealTrap`).
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
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink);

        $this->autoloaded = [];
        $trapPrefix = 'Trap\\';
        $this->tracker = function (string $class) use ($trapPrefix): void {
            // Composer's real autoloader has no `Trap\` prefix, so a probe on
            // that namespace can only come from the LSP itself — anything the
            // tracker captures is the invariant this test is protecting.
            if (str_starts_with($class, $trapPrefix)) {
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

        self::assertIsArray($result, 'the handler must answer a real response, not crash the process');
        self::assertNotEmpty($result['items'], 'a legitimate imported type must be offered');
        self::assertContains(
            'RealType',
            array_column($result['items'], 'label'),
            'the imported class must complete',
        );
        self::assertSame(
            [],
            $this->autoloaded,
            'no `Trap\` FQN may be autoloaded during completion — the walker`s bogus PSR-4'
            . ' candidate must not reach a class_exists probe',
        );
    }
}
