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
 */
final class CompletionAutoloadIsolationTest extends TestCase
{
    use BuildsKnowledgeStackTrait;
    use OpensDocumentsTrait;
    use WiresCompletionSourceTrait;

    private string $workspace = '';

    /** @var callable(string): void */
    private $tracker;

    /** @var list<string> */
    private array $autoloaded = [];

    private DocumentManager $documents;
    private CompletionHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        // A minimal workspace whose PSR-4 layout mints a "candidate" FQN that
        // no file actually declares. The walker cannot verify content through
        // Composer's file resolver alone; downstream lookup on that candidate
        // falls through every real backend and lands on BuiltinBackend, which
        // is the seam under test.
        $this->workspace = sys_get_temp_dir() . '/php-lsp-completion-autoload-' . getmypid();
        @mkdir($this->workspace . '/src', recursive: true);
        file_put_contents(
            $this->workspace . '/src/RealType.php',
            "<?php\nnamespace Trap;\nclass RealType {}\n",
        );
        // PSR-4 candidate would be `Trap\RealTrap`; the file's real namespace
        // is elsewhere, so this FQN corresponds to no actual class.
        file_put_contents(
            $this->workspace . '/src/RealTrap.php',
            "<?php\nnamespace Elsewhere;\nclass RealTrap {}\n",
        );

        $map = new ComposerAutoloadMap(psr4: ['Trap\\' => [$this->workspace . '/src']]);
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

        @unlink($this->workspace . '/src/RealType.php');
        @unlink($this->workspace . '/src/RealTrap.php');
        @rmdir($this->workspace . '/src');
        @rmdir($this->workspace);
    }

    public function testCompletionDoesNotAutoloadBogusPsr4Candidates(): void
    {
        // A parameter-type position with prefix `Real` — narrow enough that
        // the search backend's response is bounded to the workspace's own
        // matches, but wide enough that the bogus PSR-4 candidate is in it.
        $source = "<?php\n"
            . "namespace Editing;\n"
            . "\n"
            . "use Trap\\RealType;\n"
            . "\n"
            . "final class Editor\n"
            . "{\n"
            . "    public function __construct(Real";
        $uri = 'file:///editor.php';
        $this->openDocument($uri, $source);

        $line = 7;
        $character = strlen("    public function __construct(Real");
        $result = $this->handler->handle($this->completionRequestAt([
            'uri' => $uri,
            'line' => $line,
            'character' => $character,
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
