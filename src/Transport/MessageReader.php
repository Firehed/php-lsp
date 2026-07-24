<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Amp\ByteStream\ReadableStream;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Protocol\ResponseError;

final class MessageReader
{
    private const string CONTENT_LENGTH = 'content-length:';

    private string $buffer = '';

    public function __construct(
        private ReadableStream $stream,
    ) {
    }

    /**
     * Reads one frame, reporting the three outcomes RFC 1 §9 requires be told
     * apart: a usable message, a frame that could not be decoded (answer with
     * an error and keep serving), and a clean end of stream (stop serving).
     */
    public function read(): Message|MalformedFrame|EndOfStream
    {
        $contentLength = $this->readContentLength();
        if (!is_int($contentLength)) {
            return $contentLength;
        }

        $body = $this->readBody($contentLength);
        if ($body === null) {
            return new MalformedFrame(
                ResponseError::parseError('stream ended before the declared Content-Length'),
            );
        }

        return $this->decode($body);
    }

    private function readContentLength(): int|MalformedFrame|EndOfStream
    {
        while (true) {
            $headerEnd = strpos($this->buffer, "\r\n\r\n");
            if ($headerEnd !== false) {
                $headerSection = substr($this->buffer, 0, $headerEnd);
                $this->buffer = substr($this->buffer, $headerEnd + 4);

                return self::parseContentLength($headerSection)
                    ?? new MalformedFrame(ResponseError::parseError('missing Content-Length header'));
            }

            $chunk = $this->stream->read();
            if ($chunk === null) {
                if ($this->buffer === '') {
                    return new EndOfStream();
                }

                // Bytes that never terminate a header block are a truncated
                // frame, not a clean hang-up. Drop them so the next read
                // reports end of stream instead of looping on the same bytes.
                $this->buffer = '';

                return new MalformedFrame(ResponseError::parseError('incomplete header at end of stream'));
            }
            $this->buffer .= $chunk;
        }
    }

    private static function parseContentLength(string $headerSection): ?int
    {
        foreach (explode("\r\n", $headerSection) as $header) {
            if (str_starts_with(strtolower($header), self::CONTENT_LENGTH)) {
                return (int) trim(substr($header, strlen(self::CONTENT_LENGTH)));
            }
        }

        return null;
    }

    private function readBody(int $length): ?string
    {
        while (strlen($this->buffer) < $length) {
            $chunk = $this->stream->read();
            if ($chunk === null) {
                // Same reasoning as the header path: a body that never reaches
                // its declared length is a truncated frame, so its bytes are
                // consumed. Left in place they would be re-read as the next
                // frame's header block, which both costs a second error
                // response for one bad frame and lets bytes the sender chose
                // inside a body declare the Content-Length that follows them.
                $this->buffer = '';

                return null;
            }
            $this->buffer .= $chunk;
        }

        $body = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $body;
    }

    /**
     * The frame's bytes have already been consumed by the time this runs, so a
     * rejected message costs the sender an error response but does not
     * desynchronize the stream.
     */
    private function decode(string $body): Message|MalformedFrame
    {
        try {
            $data = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new MalformedFrame(ResponseError::parseError($e->getMessage()));
        }

        if (!is_array($data)) {
            return new MalformedFrame(ResponseError::invalidRequest('message must be a JSON object'));
        }

        $method = $data['method'] ?? null;
        if (!is_string($method)) {
            return new MalformedFrame(ResponseError::invalidRequest('message has no string method'));
        }

        $params = $data['params'] ?? null;
        if ($params !== null && !is_array($params)) {
            return new MalformedFrame(ResponseError::invalidRequest('params must be structured'));
        }

        if (!array_key_exists('id', $data)) {
            return NotificationMessage::fromArray($data);
        }

        if (!is_int($data['id']) && !is_string($data['id'])) {
            return new MalformedFrame(ResponseError::invalidRequest('id must be an integer or string'));
        }

        return RequestMessage::fromArray($data);
    }
}
