<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Amp\ByteStream\WritableStream;
use Firehed\PhpLsp\Protocol\ResponseMessage;

final class MessageWriter
{
    public function __construct(
        private WritableStream $stream,
    ) {
    }

    public function write(ResponseMessage $response): void
    {
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        $header = "Content-Length: " . strlen($json) . "\r\n\r\n";
        $this->stream->write($header . $json);
    }
}
