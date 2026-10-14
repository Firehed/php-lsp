<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Session;

use FilesystemIterator;
use Firehed\PhpLsp\Tests\EndToEnd\Client\LspClient;
use Firehed\PhpLsp\Tests\EndToEnd\Client\ServerMessage;
use Firehed\PhpLsp\Tests\EndToEnd\Marker\MarkerInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Step\FileChange;
use Firehed\PhpLsp\Tests\EndToEnd\Step\FileChangeType;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

/**
 * An editor session on one project: turns editor actions on project files
 * into the messages a client sends.
 */
final class Session
{
    /** @var array<string, string> Text of each open document, by file. */
    private array $buffers = [];

    /** @var array<string, int> Version of each open document, by file. */
    private array $versions = [];

    /**
     * @param string $projectRoot The physical path the server runs in. The
     *        server matches documents by path, so a symlinked spelling of the
     *        same directory would not match what it reports.
     */
    public function __construct(
        private readonly LspClient $client,
        private readonly string $projectRoot,
    ) {
    }

    public function ask(Feature $feature, string $file, MarkerInterface $at): ServerMessage
    {
        $text = $this->text($file);
        ['line' => $line, 'character' => $byteColumn] = $at->locate($text);
        $before = substr(explode("\n", $text)[$line], 0, $byteColumn);

        $response = $this->client->request($feature->value, (object) [
            'textDocument' => ['uri' => $this->uri($file)],
            'position' => [
                'line' => $line,
                // [LSP] Position: columns count UTF-16 code units unless negotiated otherwise.
                'character' => intdiv(strlen(mb_convert_encoding($before, 'UTF-16LE', 'UTF-8')), 2),
            ],
        ]);
        // A failing handler is answered with an error and a null result, which
        // would otherwise read as no answer.
        Assert::assertNull($response->error, "{$feature->value} succeeds");

        return $response;
    }

    public function close(string $file): void
    {
        assert(array_key_exists($file, $this->buffers), "Not open: {$file}");
        unset($this->buffers[$file], $this->versions[$file]);

        $this->client->notify('textDocument/didClose', (object) [
            'textDocument' => ['uri' => $this->uri($file)],
        ]);
    }

    /**
     * Writes a file on disk as another process would. Nothing is sent.
     */
    public function copy(string $from, string $to): void
    {
        $copied = copy("{$this->projectRoot}/{$from}", "{$this->projectRoot}/{$to}");
        assert($copied, "Cannot copy {$from} to {$to}");
    }

    /**
     * Deletes a file on disk as another process would. Nothing is sent.
     */
    public function delete(string $file): void
    {
        $deleted = unlink("{$this->projectRoot}/{$file}");
        assert($deleted, "Cannot delete {$file}");
    }

    /**
     * Regenerates the Composer autoloader from the project's `composer.json`,
     * then reports every PHP file it created, changed or deleted, as the
     * client's file watcher would.
     */
    public function dumpAutoload(): void
    {
        $before = $this->vendorHashes();

        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 3) . '/vendor/bin/composer',
                'dump-autoload',
                '--no-interaction',
                '--quiet',
                '--working-dir=' . $this->projectRoot,
            ],
            [],
            $pipes,
        );
        assert($process !== false, 'Cannot start composer');
        Assert::assertSame(0, proc_close($process), 'composer dump-autoload succeeds');

        $after = $this->vendorHashes();
        $changes = [];
        foreach ($after as $file => $hash) {
            if (!array_key_exists($file, $before)) {
                $changes[] = new FileChange($file, FileChangeType::Created);
            } elseif ($before[$file] !== $hash) {
                $changes[] = new FileChange($file, FileChangeType::Changed);
            }
        }
        foreach (array_diff_key($before, $after) as $file => $hash) {
            $changes[] = new FileChange($file, FileChangeType::Deleted);
        }
        $this->reportChanges($changes);
    }

    /**
     * @return ServerMessage The response to `shutdown`.
     */
    public function end(): ServerMessage
    {
        $response = $this->client->request('shutdown');
        $this->client->notify('exit');

        return $response;
    }

    /**
     * The project file a URI from the server names.
     */
    public function fileOf(string $uri): string
    {
        $prefix = "file://{$this->projectRoot}/";
        Assert::assertStringStartsWith($prefix, $uri, 'the server names a file in the project');

        return substr($uri, strlen($prefix));
    }

    public function open(string $file): void
    {
        $this->buffers[$file] = $this->text($file);
        $this->versions[$file] = 1;

        $this->client->notify('textDocument/didOpen', (object) [
            'textDocument' => [
                'uri' => $this->uri($file),
                'languageId' => 'php',
                'version' => 1,
                'text' => $this->buffers[$file],
            ],
        ]);
    }

    /**
     * Replaces the whole text of an open document with a file's contents. The
     * disk is not changed.
     */
    public function replace(string $file, string $with): void
    {
        $this->change($file, $this->text($with));
    }

    /**
     * Reports changes on disk, as the client's file watcher would.
     *
     * @param list<FileChange> $changes
     */
    public function reportChanges(array $changes): void
    {
        $this->client->notify('workspace/didChangeWatchedFiles', (object) [
            'changes' => array_map(
                fn (FileChange $change): array => ['uri' => $this->uri($change->file), 'type' => $change->type->value],
                $changes,
            ),
        ]);
    }

    /**
     * @return ServerMessage The response to `initialize`.
     */
    public function start(ClientCapabilities $capabilities): ServerMessage
    {
        $response = $this->client->request('initialize', (object) [
            'processId' => null,
            'rootUri' => 'file://' . $this->projectRoot,
            'capabilities' => $capabilities->toWire(),
        ]);
        $this->client->notify('initialized', new stdClass());

        return $response;
    }

    /**
     * Inserts text into an open document.
     */
    public function type(string $file, MarkerInterface $at, string $typed): void
    {
        assert(array_key_exists($file, $this->buffers), "Not open: {$file}");

        ['line' => $line, 'character' => $byteColumn] = $at->locate($this->buffers[$file]);
        $lines = explode("\n", $this->buffers[$file]);
        $lines[$line] = substr_replace($lines[$line], $typed, $byteColumn, 0);

        $this->change($file, implode("\n", $lines));
    }

    /**
     * The server advertises full-document sync, so the whole new text is sent
     * ([LSP] textDocument/didChange).
     */
    private function change(string $file, string $text): void
    {
        assert(array_key_exists($file, $this->buffers), "Not open: {$file}");
        $this->buffers[$file] = $text;

        $this->client->notify('textDocument/didChange', (object) [
            'textDocument' => ['uri' => $this->uri($file), 'version' => ++$this->versions[$file]],
            'contentChanges' => [['text' => $text]],
        ]);
    }

    /**
     * What the editor shows for a file: its buffer when open, else the disk.
     */
    private function text(string $file): string
    {
        if (array_key_exists($file, $this->buffers)) {
            return $this->buffers[$file];
        }

        $text = file_get_contents("{$this->projectRoot}/{$file}");
        assert($text !== false, "Not in the project: {$file}");

        return $text;
    }

    /**
     * @return array<string, string> Content hash of each PHP file under
     *         vendor/, by file.
     */
    private function vendorHashes(): array
    {
        $hashes = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator("{$this->projectRoot}/vendor", FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($this->projectRoot) + 1);
                $hashes[$relative] = (string) hash_file('sha1', $file->getPathname());
            }
        }

        return $hashes;
    }

    private function uri(string $file): string
    {
        return "file://{$this->projectRoot}/{$file}";
    }
}
