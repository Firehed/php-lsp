<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Session;

use Firehed\PhpLsp\Tests\EndToEnd\Client\LspClient;
use Firehed\PhpLsp\Tests\EndToEnd\Client\ServerMessage;
use Firehed\PhpLsp\Tests\EndToEnd\Marker\MarkerInterface;
use PHPUnit\Framework\Assert;
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
     * @return ServerMessage The response to `initialize`.
     */
    public function start(): ServerMessage
    {
        $response = $this->client->request('initialize', (object) [
            'processId' => null,
            'rootUri' => 'file://' . $this->projectRoot,
            'capabilities' => new stdClass(),
        ]);
        $this->client->notify('initialized', new stdClass());

        return $response;
    }

    /**
     * Inserts text into an open document. The server advertises full-document
     * sync, so the whole new text is sent ([LSP] textDocument/didChange).
     */
    public function type(string $file, MarkerInterface $at, string $typed): void
    {
        assert(array_key_exists($file, $this->buffers), "Not open: {$file}");

        ['line' => $line, 'character' => $byteColumn] = $at->locate($this->buffers[$file]);
        $lines = explode("\n", $this->buffers[$file]);
        $lines[$line] = substr_replace($lines[$line], $typed, $byteColumn, 0);
        $this->buffers[$file] = implode("\n", $lines);

        $this->client->notify('textDocument/didChange', (object) [
            'textDocument' => ['uri' => $this->uri($file), 'version' => ++$this->versions[$file]],
            'contentChanges' => [['text' => $this->buffers[$file]]],
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

    private function uri(string $file): string
    {
        return "file://{$this->projectRoot}/{$file}";
    }
}
