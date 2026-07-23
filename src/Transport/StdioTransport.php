<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\WritableStream;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\ResponseMessage;

use const STDIN;
use const STDOUT;

final class StdioTransport implements TransportInterface
{
    private MessageReader $reader;
    private MessageWriter $writer;

    /**
     * Defaults to the process's standard streams, which is how the server runs;
     * both are injectable so the framing round-trip can be exercised without
     * taking over the test runner's stdio.
     */
    public function __construct(
        private readonly ReadableStream $input = new ReadableResourceStream(STDIN),
        private readonly WritableStream $output = new WritableResourceStream(STDOUT),
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
