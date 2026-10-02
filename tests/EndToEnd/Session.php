<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use stdClass;

/**
 * An editor session on one project: turns editor actions on project files
 * into the messages a client sends.
 */
final class Session
{
    use LoadsFixturesTrait;

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

    /**
     * @param string $symbolMarker A `//hover:name` marker in the file.
     */
    public function ask(Feature $feature, string $file, string $symbolMarker): void
    {
        $text = $this->text($file);
        ['line' => $line, 'character' => $byteColumn] = $this->locateHoverMarker($text, $symbolMarker);
        $before = substr(explode("\n", $text)[$line], 0, $byteColumn);

        $this->client->request($feature->value, (object) [
            'textDocument' => ['uri' => $this->uri($file)],
            'position' => [
                'line' => $line,
                // [LSP] Position: columns count UTF-16 code units unless negotiated otherwise.
                'character' => intdiv(strlen(mb_convert_encoding($before, 'UTF-16LE', 'UTF-8')), 2),
            ],
        ]);
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

    public function open(string $file): void
    {
        $this->client->notify('textDocument/didOpen', (object) [
            'textDocument' => [
                'uri' => $this->uri($file),
                'languageId' => 'php',
                'version' => 1,
                'text' => $this->text($file),
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

    private function text(string $file): string
    {
        $text = file_get_contents("{$this->projectRoot}/{$file}");
        assert($text !== false, "Not in the project: {$file}");

        return $text;
    }

    private function uri(string $file): string
    {
        return "file://{$this->projectRoot}/{$file}";
    }
}
