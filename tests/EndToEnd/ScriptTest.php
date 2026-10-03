<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use FilesystemIterator;
use Firehed\PhpLsp\Tests\Parity\GoldenCodec;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Runs each script in `scripts/` against a real server and compares the
 * conversation to the transcript recorded beside it. Recapture a transcript
 * with `UPDATE_GOLDENS=1` and review the diff.
 */
#[CoversNothing]
final class ScriptTest extends TestCase
{
    use StartsServerTrait;

    private const string SCRIPTS = __DIR__ . '/scripts';

    private ?string $copy = null;

    #[DataProvider('scripts')]
    public function testConversationMatchesItsTranscript(string $name): void
    {
        // Loaded in its own scope: a script may define variables of its own.
        $script = (static fn(string $path): mixed => require $path)(self::SCRIPTS . "/{$name}.php");
        self::assertInstanceOf(Script::class, $script, 'a script file returns a Script');

        $projectRoot = $this->copyProject($script->project);
        $server = $this->startServer($projectRoot);
        $client = new LspClient($server);
        $session = new Session($client, $projectRoot);

        $session->start($script->capabilities);
        foreach ($script->steps as $step) {
            $step->run($session);
        }
        $session->end();

        self::assertSame(0, $server->waitForExit(), 'the session ends cleanly');
        self::assertSame('', $server->stderr(), 'the server writes nothing to stderr');

        // The server reports paths under its physical working directory, which
        // differs per machine.
        $recorded = str_replace($projectRoot, '{project}', GoldenCodec::encode($client->transcript));
        $transcript = self::SCRIPTS . "/{$name}.json";
        if (getenv('UPDATE_GOLDENS') === '1') {
            file_put_contents($transcript, $recorded);
        }
        self::assertJsonStringEqualsJsonFile(
            $transcript,
            $recorded,
            "Script '{$name}' does not match its transcript. If the change is intended, "
                . 'record it with UPDATE_GOLDENS=1 and review the diff.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scripts(): iterable
    {
        $files = glob(self::SCRIPTS . '/*.php');
        assert($files !== false);

        foreach ($files as $file) {
            $name = basename($file, '.php');
            yield $name => [$name];
        }
    }

    protected function tearDown(): void
    {
        if ($this->copy === null) {
            return;
        }
        $contents = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->copy, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($contents as $item) {
            assert($item instanceof SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->copy);
    }

    /**
     * A throwaway copy of a project, so a script may change files on disk.
     *
     * @return string The copy's physical path.
     */
    private function copyProject(string $project): string
    {
        $source = $this->projectRoot($project);
        $copy = sys_get_temp_dir() . '/php-lsp-e2e-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($copy), 'a temporary project directory can be created');
        $contents = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($contents as $item) {
            assert($item instanceof SplFileInfo);
            $target = $copy . substr($item->getPathname(), strlen($source));
            $item->isDir() ? mkdir($target) : copy($item->getPathname(), $target);
        }

        // The temporary directory may itself be reached through a symlink.
        $physical = realpath($copy);
        assert($physical !== false);
        $this->copy = $physical;

        return $physical;
    }
}
