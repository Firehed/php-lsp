<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\ResponseMessage;

use const STDIN;
use const STDOUT;

final class StdioTransport implements TransportInterface
{
    private MessageReader $reader;
    private MessageWriter $writer;
    private ReadableResourceStream $stdin;
    private WritableResourceStream $stdout;

    public function __construct()
    {
        $this->stdin = new ReadableResourceStream(STDIN);
        $this->stdout = new WritableResourceStream(STDOUT);
        $this->reader = new MessageReader($this->stdin);
        $this->writer = new MessageWriter($this->stdout);
    }

    public function read(): ?Message
    {
        return $this->reader->read();
    }

    public function write(ResponseMessage $response): void
    {
        $this->writer->write($response);
    }

    public function close(): void
    {
        $this->stdin->close();
        $this->stdout->close();
    }
}
