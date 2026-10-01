<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use Amp\CancelledException;
use Amp\Process\Process;
use Amp\TimeoutCancellation;

use function Amp\ByteStream\buffer;

/**
 * A running `bin/php-lsp`, addressed in bytes and frames. Framing is done here
 * rather than with the server's own transport classes so that a framing bug in
 * the server cannot be mirrored by the test reading it.
 */
final class ServerProcess
{
    private const float DEFAULT_DEADLINE = 1.0;

    private string $unread = '';

    private function __construct(private readonly Process $process)
    {
    }

    /**
     * @param string $projectRoot The server takes its project from its working directory.
     */
    public static function start(string $projectRoot): self
    {
        return new self(Process::start(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/php-lsp'],
            $projectRoot,
        ));
    }

    public function write(string $bytes): void
    {
        $this->process->getStdin()->write($bytes);
    }

    public function closeInput(): void
    {
        $this->process->getStdin()->end();
    }

    /**
     * @return string The body of the next frame, without its header.
     */
    public function readFrame(float $deadline = self::DEFAULT_DEADLINE): string
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
     * Ends the server: a session that missed a frame is unusable, and its
     * stderr is only complete once it has exited.
     */
    private function frameNotReceived(string $reason): FrameNotReceived
    {
        $this->process->kill();

        return new FrameNotReceived($reason, $this->unread, $this->stderr());
    }
}
