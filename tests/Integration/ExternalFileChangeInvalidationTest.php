<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Handler\DidChangeWatchedFilesHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The S3.5 acceptance criteria end to end (Plan 0002 Step 3; RFC 1 §5.2, §5.3):
 * against a real on-disk workspace, an external edit to an unopened file, a branch
 * checkout, and a deletion are each reflected on the next query, and closing an
 * edited file re-reads from disk rather than restoring the pre-edit cache.
 *
 * The change notifications drive the real handler → {@see \Firehed\PhpLsp\Knowledge\SymbolSink}
 * → backend chain; the workspace is a temp directory mutated between queries.
 */
#[CoversNothing]
class ExternalFileChangeInvalidationTest extends TestCase
{
    private const string NAMESPACE = 'Temp\\';

    private string $workspace;
    private ParserService $parser;

    protected function setUp(): void
    {
        $workspace = tempnam(sys_get_temp_dir(), 'php-lsp-s35-');
        self::assertNotFalse($workspace, 'a temp workspace path must be obtainable');
        unlink($workspace);
        self::assertTrue(mkdir($workspace), 'the temp workspace directory must be created');

        $this->workspace = $workspace;
        $this->parser = new ParserService();
    }

    protected function tearDown(): void
    {
        $files = glob($this->workspace . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->workspace);
    }

    public function testAnExternalEditToAnUnopenedFileIsReflectedOnTheNextQuery(): void
    {
        $this->writeClass('Widget', '');
        $stack = $this->stack();
        $handler = new DidChangeWatchedFilesHandler($stack->sink);

        $before = $stack->source->lookupClassLike($this->classNameFor('Widget'));
        self::assertNotNull($before, 'the unopened workspace class must resolve from disk');
        self::assertArrayNotHasKey('greet', $before->methods, 'the original file declares no greet() method');

        $this->writeClass('Widget', 'public function greet(): void {}');
        $handler->handle($this->changed('Widget'));

        $after = $stack->source->lookupClassLike($this->classNameFor('Widget'));
        self::assertNotNull($after, 'the class still resolves after the edit');
        self::assertArrayHasKey(
            'greet',
            $after->methods,
            'the external edit must be reflected on the next query rather than serving the cached class',
        );
    }

    public function testABranchCheckoutIsReflectedOnTheNextQuery(): void
    {
        // A checkout changes several files at once; the client reports one event per file.
        $this->writeClass('Alpha', '');
        $this->writeClass('Beta', '');
        $stack = $this->stack();
        $handler = new DidChangeWatchedFilesHandler($stack->sink);

        $this->warm($stack->source, 'Alpha', 'Beta');

        $this->writeClass('Alpha', 'public function onAlpha(): void {}');
        $this->writeClass('Beta', 'public function onBeta(): void {}');
        $handler->handle($this->changed('Alpha', 'Beta'));

        $alpha = $stack->source->lookupClassLike($this->classNameFor('Alpha'));
        $beta = $stack->source->lookupClassLike($this->classNameFor('Beta'));
        self::assertNotNull($alpha);
        self::assertNotNull($beta);
        self::assertArrayHasKey('onAlpha', $alpha->methods, 'each checked-out file must be re-read');
        self::assertArrayHasKey('onBeta', $beta->methods, 'each checked-out file must be re-read');
    }

    public function testAFileDeletionIsReflectedOnTheNextQuery(): void
    {
        $this->writeClass('Widget', '');
        $stack = $this->stack();
        $handler = new DidChangeWatchedFilesHandler($stack->sink);

        self::assertNotNull(
            $stack->source->lookupClassLike($this->classNameFor('Widget')),
            'the class resolves while its file exists',
        );

        unlink($this->workspace . '/Widget.php');
        $handler->handle($this->changed('Widget'));

        self::assertNull(
            $stack->source->lookupClassLike($this->classNameFor('Widget')),
            'a deleted file must resolve to nothing on the next query, not the cached class',
        );
    }

    public function testClosingAnEditedFileReReadsFromDiskRatherThanRestoringThePreEditCache(): void
    {
        $this->writeClass('Widget', '');
        $stack = $this->stack();
        $uri = $this->uriFor('Widget');

        // The file is cached from disk, then opened and edited in the editor and
        // saved to disk with a new method.
        self::assertNotNull($stack->source->lookupClassLike($this->classNameFor('Widget')));
        $stack->sink->openDocument(new TextDocument($uri, 'php', 1, $this->classSource('Widget', '')));
        $this->writeClass('Widget', 'public function saved(): void {}');

        $stack->sink->closeDocument($uri);

        $reopened = $stack->source->lookupClassLike($this->classNameFor('Widget'));
        self::assertNotNull($reopened, 'the class still resolves from disk after close');
        self::assertArrayHasKey(
            'saved',
            $reopened->methods,
            'closing an edited file must re-read disk, not restore the pre-edit cached class (RFC 1 §5.3)',
        );
    }

    private function warm(SymbolSource $source, string ...$shortNames): void
    {
        foreach ($shortNames as $shortName) {
            self::assertNotNull(
                $source->lookupClassLike($this->classNameFor($shortName)),
                "{$shortName} must resolve so its pre-change value is cached",
            );
        }
    }

    private function stack(): KnowledgeStack
    {
        return KnowledgeStack::forProject(
            new ComposerAutoloadMap(psr4: [self::NAMESPACE => [$this->workspace]]),
            $this->workspace . '/vendor',
            $this->parser,
        );
    }

    private function writeClass(string $shortName, string $body): void
    {
        self::assertNotFalse(
            file_put_contents($this->workspace . '/' . $shortName . '.php', $this->classSource($shortName, $body)),
            "the fixture class {$shortName} must be writable",
        );
    }

    private function classSource(string $shortName, string $body): string
    {
        return "<?php\nnamespace Temp;\nclass {$shortName} {\n{$body}\n}\n";
    }

    private function changed(string ...$shortNames): NotificationMessage
    {
        $changes = [];
        foreach ($shortNames as $shortName) {
            // FileChangeType.Changed = 2; the handler treats every type alike.
            $changes[] = ['uri' => $this->uriFor($shortName), 'type' => 2];
        }

        return NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => $changes],
        ]);
    }

    private function uriFor(string $shortName): string
    {
        return 'file://' . $this->workspace . '/' . $shortName . '.php';
    }

    private function classNameFor(string $shortName): ClassName
    {
        /** @phpstan-ignore argument.type (temp fixture names are not analyzed) */
        return new ClassName('Temp\\' . $shortName);
    }
}
