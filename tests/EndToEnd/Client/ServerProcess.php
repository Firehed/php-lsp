<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Client;

use Amp\CancelledException;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use stdClass;

use function Amp\ByteStream\buffer;

/**
 * A running `bin/php-lsp`, written to in bytes and read in messages. Framing is done here
 * rather than with the server's own transport classes so that a framing bug in
 * the server cannot be mirrored by the test reading it.
 */
final class ServerProcess
{
    private const float DEFAULT_DEADLINE = 1.0;

    private string $unread = '';

    public function __construct(private readonly Process $process)
    {
    }

    public function write(string $bytes): void
    {
        $this->process->getStdin()->write($bytes);
    }

    public function closeInput(): void
    {
        $this->process->getStdin()->end();
    }

    public function readMessage(float $deadline = self::DEFAULT_DEADLINE): ServerMessage
    {
        $body = json_decode($this->readFrame($deadline), flags: JSON_THROW_ON_ERROR);
        assert($body instanceof stdClass);

        $id = $body->id ?? null;
        $method = $body->method ?? null;
        $params = $body->params ?? null;
        $result = $body->result ?? null;
        $error = $body->error ?? null;
        assert($id === null || is_int($id) || is_string($id));
        assert($method === null || is_string($method));
        assert($params === null || $params instanceof stdClass || self::isList($params));
        assert($result === null || is_scalar($result) || $result instanceof stdClass || self::isList($result));
        assert($error === null || $error instanceof stdClass);

        return new ServerMessage($id, $method, $params, $result, $error);
    }

    /**
     * Everything the server wrote to stderr. Waits for the server to exit, and
     * consumes the stream: a second call returns nothing.
     */
    public function stderr(): string
    {
        return buffer($this->process->getStderr());
    }

    public function waitForExit(float $deadline = self::DEFAULT_DEADLINE): int
    {
        return $this->process->join(new TimeoutCancellation($deadline));
    }

    /**
     * @phpstan-assert-if-true list<mixed> $value
     */
    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * @return string The body of the next frame, without its header.
     */
    private function readFrame(float $deadline): string
    {
        $cancellation = new TimeoutCancellation($deadline);

        try {
            while (true) {
                if (preg_match('/^Content-Length: (\d+)\r\n\r\n/', $this->unread, $header) === 1) {
                    $bodyStart = strlen($header[0]);
                    $bodyLength = (int) $header[1];
                    if (strlen($this->unread) >= $bodyStart + $bodyLength) {
                        $body = substr($this->unread, $bodyStart, $bodyLength);
                        $this->unread = substr($this->unread, $bodyStart + $bodyLength);

                        return $body;
                    }
                }

                $chunk = $this->process->getStdout()->read($cancellation);
                if ($chunk === null) {
                    throw $this->frameNotReceived('The server closed its output.');
                }
                $this->unread .= $chunk;
            }
        } catch (CancelledException) {
            throw $this->frameNotReceived("No frame within {$deadline}s.");
        }
    }

    /**
     * Ends the server: a session that missed a frame is unusable, and its
     * stderr is only complete once it has exited.
     */
    private function frameNotReceived(string $reason): FrameNotReceivedException
    {
        $this->process->kill();

        return new FrameNotReceivedException($reason, $this->unread, $this->stderr());
    }
}
