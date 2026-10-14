<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\PhpLsp\Handler\HandlerInterface;
use Firehed\PhpLsp\Handler\LifecycleHandler;
use Firehed\PhpLsp\Parser\SyntaxSource\MessageScopedInterface;
use Firehed\PhpLsp\Protocol\ErrorCode;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Protocol\ResponseError;
use Firehed\PhpLsp\Protocol\ResponseMessage;
use Firehed\PhpLsp\Transport\EndOfStream;
use Firehed\PhpLsp\Transport\MalformedFrame;
use Firehed\PhpLsp\Transport\TransportInterface;

final class Server
{
    /**
     * @param LifecycleHandler $lifecycleHandler Consulted before $handlers. The
     *        loop also asks it for the lifecycle gate and the exit code, so the
     *        instance that gates a message is the one that handled `initialize`
     *        and `shutdown`.
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly LifecycleHandler $lifecycleHandler,
        private readonly HandlerInterface $handlers,
        private readonly MessageScopedInterface $messageScope,
    ) {
    }

    public function run(): int
    {
        while (true) {
            $message = $this->transport->read();

            if ($message instanceof EndOfStream) {
                break;
            }

            if ($message instanceof MalformedFrame) {
                // Answered at whatever id the reader could correlate the frame
                // to, and at the JSON-RPC null id when it could recover none.
                // The loop continues: one bad frame must not end
                // the session.
                $this->transport->write(ResponseMessage::error($message->id, $message->error));
                continue;
            }

            $result = null;

            try {
                // Lifecycle gate: a message not permitted in the current
                // state is answered with the lifecycle error and never dispatched. A
                // gated notification has no id, so its error is simply dropped.
                $error = $this->lifecycleHandler->lifecycleErrorFor($message);

                if ($error === null) {
                    try {
                        // Inside the try because `supports()` is part of the
                        // handler contract: a failure selecting a handler is a
                        // handler failure, and must be answered rather than
                        // crashing the read loop.
                        $handler = $this->findHandler($message->method);

                        if ($handler !== null) {
                            $result = $handler->handle($message);
                        } elseif ($message instanceof RequestMessage) {
                            $error = new ResponseError(
                                ErrorCode::MethodNotFound,
                                "Method not found: {$message->method}",
                            );
                        }
                    } catch (\Throwable $e) {
                        // A failing handler must not take the read loop down with it:
                        // an editor session that dies on one bad request
                        // loses all unsaved server state. Notifications have no id to
                        // answer, so their failure is contained and dropped.
                        //
                        // Forwarding the raw message crosses no trust boundary: the
                        // client is the editor that spawned this process over its own
                        // stdio pipe, and it already has the server's whole filesystem
                        // view. Paths it may carry are the user's own, bound for the
                        // user's own LSP log, which is what makes an unreproducible
                        // crash diagnosable. `message` stays generic; per [LSP] "Base
                        // Protocol", ResponseError.data is where detail belongs.
                        $error = new ResponseError(ErrorCode::InternalError, data: $e->getMessage());
                    }
                }
            } finally {
                // Per-message state (the parse memo, and any later MessageScopedInterface
                // decorator) closes here — this loop is the only boundary that knows
                // where the message ends. Clearing it through MessageScopedInterface keeps
                // Server from naming the decorator, and keeps the memo from becoming
                // a standing cache.
                $this->messageScope->endMessage();
            }

            // Send response for requests (not notifications)
            if ($message instanceof RequestMessage) {
                if ($error !== null) {
                    $response = ResponseMessage::error($message->id, $error);
                } else {
                    $response = ResponseMessage::success($message->id, $result);
                }
                $this->writeResponse($message->id, $response);
            }

            // Check for exit
            $exitCode = $this->lifecycleHandler->getExitCode();
            if ($exitCode !== null) {
                $this->transport->close();
                return $exitCode;
            }
        }

        $this->transport->close();
        return 1;
    }

    /**
     * A result a handler produced but the encoder cannot represent fails here
     * rather than in `handle()`, so the dispatch catch never sees it. It is
     * still a handler failure and must not take the read loop down with it;
     * text lifted from a file that is not valid UTF-8 reaches the
     * writer exactly this way.
     *
     * The replacement carries only the exception message and the decoded id,
     * both of which are known-encodable, so answering cannot fail the same way.
     * The frame is encoded before any bytes are written, so the failed response
     * leaves nothing half-written on the wire.
     *
     * The forwarded message discloses nothing: json_encode's failures come from
     * PHP's fixed json_last_error_msg table ("Malformed UTF-8 characters…",
     * "Recursion detected", …), which interpolate none of the value that failed.
     */
    private function writeResponse(int|string $id, ResponseMessage $response): void
    {
        try {
            $this->transport->write($response);
        } catch (\JsonException $e) {
            $this->transport->write(
                ResponseMessage::error($id, new ResponseError(ErrorCode::InternalError, data: $e->getMessage())),
            );
        }
    }

    private function findHandler(string $method): ?HandlerInterface
    {
        if ($this->lifecycleHandler->supports($method)) {
            return $this->lifecycleHandler;
        }
        if ($this->handlers->supports($method)) {
            return $this->handlers;
        }
        return null;
    }
}
