<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\ResponseMessage;

/**
 * Frames LSP messages over a stream pair, per [LSP] "Base Protocol".
 *
 * The streams are supplied by the caller: acquiring the process's real stdio
 * handles cannot be exercised from a test, so it belongs in the entry point that
 * composes the server (`bin/php-lsp`), not in here.
 */
final class StreamTransport implements TransportInterface
{
    private MessageReader $reader;
    private MessageWriter $writer;

    public function __construct(
        private readonly ReadableStream $input,
        private readonly WritableStream $output,
    ) {
        $this->reader = new MessageReader($this->input);
        $this->writer = new MessageWriter($this->output);
    }

    public function read(): Message|MalformedFrame|EndOfStream
    {
        return $this->reader->read();
    }

    public function write(ResponseMessage $response): void
    {
        $this->writer->write($response);
    }

    public function close(): void
    {
        $this->input->close();
        $this->output->close();
    }
}
