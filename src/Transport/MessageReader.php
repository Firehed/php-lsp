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
        while (true) {
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

            $outcome = $this->decode($body);

            // A frame that is recognisably a Notification is never answered
            // (JSON-RPC 2.0 §4.1), so an unusable one leaves nothing to report
            // and nothing to dispatch. Its bytes are already consumed; read on
            // to the next frame rather than inventing an outcome for it.
            if ($outcome === null) {
                continue;
            }

            return $outcome;
        }
    }

    private function readContentLength(): int|MalformedFrame|EndOfStream
    {
        while (true) {
            $headerEnd = strpos($this->buffer, "\r\n\r\n");
            if ($headerEnd !== false) {
                $headerSection = substr($this->buffer, 0, $headerEnd);
                $this->buffer = substr($this->buffer, $headerEnd + 4);

                // Without a usable length the frame's extent is unknown, so the
                // rest of the buffer is offered to the decoder instead. The
                // content part is JSON, which the decoder can judge on its own:
                // a client that merely mis-declared the length is served, and
                // anything else costs one error (see parseContentLength).
                return self::parseContentLength($headerSection) ?? strlen($this->buffer);
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

    /**
     * Null when no usable Content-Length is present. Per [LSP] "Base Protocol"
     * it is a byte count whose header field structure "conforms to the HTTP
     * semantic" ([RFC 7230] §3.2), and so is §3.3.2's `Content-Length = 1*DIGIT`.
     *
     * A bare (int) cast accepted whatever the sender sent: "abc" framed a
     * zero-length body and "-5" a negative one, which makes substr() consume
     * from the wrong end and corrupts every frame that follows.
     *
     * Rejecting the *value* is not the same as rejecting the message. [RFC 7230]
     * §3.3.3 treats an invalid Content-Length as unrecoverable framing, and it
     * is — for opaque bytes. An LSP content part is JSON, so the decoder can
     * still tell a whole message from rubbish, and the caller falls back to it
     * rather than bouncing a client that got only the header wrong.
     */
    private static function parseContentLength(string $headerSection): ?int
    {
        $lengths = [];

        foreach (explode("\r\n", $headerSection) as $header) {
            if (str_starts_with(strtolower($header), self::CONTENT_LENGTH)) {
                $value = trim(substr($header, strlen(self::CONTENT_LENGTH)));

                if (!ctype_digit($value)) {
                    return null;
                }

                $lengths[] = (int) $value;
            }
        }

        // Conflicting values are unrecoverable framing ([RFC 7230] §3.3.3), and
        // taking the first silently is the worst option: it is what lets two
        // readers of the same bytes disagree about where a frame ends. Repeats
        // of one value say nothing contradictory, so they still frame.
        return count(array_unique($lengths)) === 1 ? $lengths[0] : null;
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
     *
     * Null means the frame is unusable *and* owes no answer: it is recognisably
     * a Notification, which JSON-RPC 2.0 §4.1 forbids replying to. Every other
     * rejection is answered, at the id it could be correlated to.
     */
    private function decode(string $body): Message|MalformedFrame|null
    {
        try {
            $data = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new MalformedFrame(ResponseError::parseError($e->getMessage()));
        }

        if (!is_array($data)) {
            return new MalformedFrame(ResponseError::invalidRequest('message must be a JSON object'));
        }

        $id = self::recoverId($data);

        // A non-string method is not a usable Notification even without an id,
        // so it is still answered — JSON-RPC 2.0's own worked example replies
        // to `{"jsonrpc":"2.0","method":1,"params":"bar"}` with a null id.
        $method = $data['method'] ?? null;
        if (!is_string($method)) {
            return new MalformedFrame(ResponseError::invalidRequest('message has no string method'), $id);
        }

        $params = $data['params'] ?? null;
        if ($params !== null && !is_array($params)) {
            if (!array_key_exists('id', $data)) {
                return null;
            }

            return new MalformedFrame(ResponseError::invalidRequest('params must be structured'), $id);
        }

        if (!array_key_exists('id', $data)) {
            return NotificationMessage::fromArray($data);
        }

        if ($id === null) {
            return new MalformedFrame(ResponseError::invalidRequest('id must be an integer or string'));
        }

        return RequestMessage::fromArray($data);
    }

    /**
     * The id a rejected frame is answered at, or null when the frame carries
     * none that could be detected (JSON-RPC 2.0 §5).
     *
     * @param array<array-key, mixed> $data
     */
    private static function recoverId(array $data): int|string|null
    {
        $id = $data['id'] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }
}
