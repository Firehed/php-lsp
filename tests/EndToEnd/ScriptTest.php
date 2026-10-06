<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use FilesystemIterator;
use Firehed\PhpLsp\Tests\EndToEnd\Client\LspClient;
use Firehed\PhpLsp\Tests\EndToEnd\Client\StartsServerTrait;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Script;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Runs each script against a real server; its steps check what the server
 * answers. A script in `transcripts/` also locks the whole conversation to
 * the transcript recorded beside it: recapture one with `UPDATE_GOLDENS=1`
 * and review the diff.
 */
#[CoversNothing]
final class ScriptTest extends TestCase
{
    use StartsServerTrait;

    private const string SCRIPTS = __DIR__ . '/scripts';

    private const string TRANSCRIPTS = __DIR__ . '/transcripts';

    private ?string $copy = null;

    #[DataProvider('scripts')]
    public function testScriptMeetsItsExpectations(Script $script): void
    {
        $this->runScript($script);
    }

    #[DataProvider('transcripts')]
    public function testConversationMatchesItsTranscript(string $name): void
    {
        $script = self::load(self::TRANSCRIPTS . "/{$name}.php");
        self::assertInstanceOf(Script::class, $script, 'a transcript file returns one Script');
        ['client' => $client, 'projectRoot' => $projectRoot] = $this->runScript($script);

        // The server reports paths under its physical working directory, which
        // differs per machine.
        $encoded = json_encode(
            $client->transcript,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        $recorded = str_replace($projectRoot, '{project}', $encoded);
        $transcript = self::TRANSCRIPTS . "/{$name}.json";
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
     * A file returns one Script, or several keyed by case name.
     *
     * @return iterable<string, array{Script}>
     */
    public static function scripts(): iterable
    {
        foreach (self::namesIn(self::SCRIPTS) as $name) {
            $loaded = self::load(self::SCRIPTS . "/{$name}.php");
            if ($loaded instanceof Script) {
                yield $name => [$loaded];
                continue;
            }
            assert(is_array($loaded) && $loaded !== [], "{$name} returns a Script or a non-empty array of them");
            foreach ($loaded as $case => $script) {
                assert(is_string($case) && $script instanceof Script, "{$name} keys each Script by case name");
                yield "{$name}: {$case}" => [$script];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transcripts(): iterable
    {
        foreach (self::namesIn(self::TRANSCRIPTS) as $name) {
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
     * Loaded in its own scope: a script file may define variables of its own.
     */
    private static function load(string $path): mixed
    {
        return (static fn(string $path): mixed => require $path)($path);
    }

    /**
     * @return list<string>
     */
    private static function namesIn(string $directory): array
    {
        $files = glob("{$directory}/*.php");
        assert($files !== false);

        return array_map(static fn (string $file): string => basename($file, '.php'), $files);
    }

    /**
     * @return array{client: LspClient, projectRoot: string}
     */
    private function runScript(Script $script): array
    {
        $projectRoot = $this->copyProject($script->project);
        $server = $this->startServer($projectRoot);
        $client = new LspClient($server);
        $session = new Session($client, $projectRoot);

        $session->start();
        foreach ($script->steps as $index => $step) {
            try {
                $step->run($session);
            } catch (ExpectationFailedException $failure) {
                $number = $index + 1;
                throw new ExpectationFailedException(
                    "Step {$number}: {$failure->getMessage()}",
                    $failure->getComparisonFailure(),
                    $failure,
                );
            }
        }
        $session->end();

        self::assertSame(0, $server->waitForExit(), 'the session ends cleanly');
        self::assertSame('', $server->stderr(), 'the server writes nothing to stderr');

        return ['client' => $client, 'projectRoot' => $projectRoot];
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
