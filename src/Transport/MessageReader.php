<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Amp\ByteStream\ReadableStream;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;

final class MessageReader
{
    private string $buffer = '';

    public function __construct(
        private ReadableStream $stream,
    ) {
    }

    public function read(): ?Message
    {
        $contentLength = $this->readHeaders();
        if ($contentLength === null) {
            return null;
        }

        $body = $this->readBody($contentLength);
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));

        if (array_key_exists('id', $data)) {
            return RequestMessage::fromArray($data);
        }

        return NotificationMessage::fromArray($data);
    }

    private function readHeaders(): ?int
    {
        $contentLength = null;

        while (true) {
            $headerEnd = strpos($this->buffer, "\r\n\r\n");
            if ($headerEnd !== false) {
                $headerSection = substr($this->buffer, 0, $headerEnd);
                $this->buffer = substr($this->buffer, $headerEnd + 4);

                foreach (explode("\r\n", $headerSection) as $header) {
                    if (str_starts_with(strtolower($header), 'content-length:')) {
                        $contentLength = (int) trim(substr($header, 15));
                    }
                }

                return $contentLength;
            }

            $chunk = $this->stream->read();
            if ($chunk === null) {
                return null;
            }
            $this->buffer .= $chunk;
        }
    }

    private function readBody(int $length): ?string
    {
        while (strlen($this->buffer) < $length) {
            $chunk = $this->stream->read();
            if ($chunk === null) {
                return null;
            }
            $this->buffer .= $chunk;
        }

        $body = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $body;
    }
}
